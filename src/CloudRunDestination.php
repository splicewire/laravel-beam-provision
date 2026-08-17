<?php

namespace Splicewire\Beam\Provision;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Splicewire\Beam\Provision\Events\Provisioned;
use Splicewire\Beam\Provision\Events\ProvisionFailed;
use Splicewire\Beam\Provision\Events\ProvisioningStarted;
use Splicewire\Beam\Provision\Events\TearingDown;
use Splicewire\Beam\Provision\Events\TornDown;
use Splicewire\Beam\Provision\Tofu\TenantDeployConfig;
use Splicewire\Beam\Provision\Tofu\TenantRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Provision\Tofu\TofuApplyRequest;
use Splicewire\Beam\Provision\Tofu\TofuApplyResult;
use Throwable;

/**
 * The GCP Cloud Run {@see Destination} adapter, rebuilt on the OpenTofu substrate
 * (gcp-cloud-run-provisioning map, ticket 10) — supersedes the raw-REST implementation ticket 04
 * found couldn't reach a real deploy (Cloud Run's git-integration turns out to be Console/internal-
 * only, not composable from documented public REST). `provision()`/`teardown()` now render a
 * per-tenant root config ({@see TenantRootConfigRenderer}, against ticket 07's `cloud-run-service`
 * + `tenant-database` modules) and drive it through ticket 09's {@see TofuApplyDispatcher}.
 * `healthCheck()` stays a direct, cheap Cloud Run Admin API read — no apply needed for that.
 *
 * **The flagged wrinkle this adapter is the other half of** (see {@see RepoRef}'s docblock): this
 * adapter needs a pre-built image URI (`RepoRef->image`) — it does not build or push one itself.
 * `Destination`'s method signatures are unchanged from the raw-REST implementation — the actual
 * proof the seam survives a from-scratch reimplementation behind it.
 */
class CloudRunDestination implements Destination
{
    protected const RUN_BASE_URL = 'https://run.googleapis.com/v2';

    /** Used only when tearing down — destroy doesn't need the tenant's real image, just *a* value for this required-without-default module variable. */
    protected const TEARDOWN_PLACEHOLDER_IMAGE = 'us-docker.pkg.dev/cloudrun/container/hello';

    public function __construct(
        protected string $project,
        protected string $region,
        protected string $network,
        protected string $subnetwork,
        protected TofuApplyDispatcher $tofu,
        protected TenantRootConfigRenderer $renderer,
        protected string $rootConfigsRoot,
        // Target-wide, not per-tenant: an org policy blocking `allUsers` (found live, ticket 10)
        // is a property of the GCP org this destination deploys into, not of any one tenant.
        protected bool $allowUnauthenticated = true,
    ) {}

    public function provision(string $destinationCredential, RepoRef $repoRef, string $domain, array $envOverrides = []): ProvisionResult
    {
        if ($repoRef->image === null) {
            throw new InvalidArgumentException(
                "CloudRunDestination requires RepoRef->image: this adapter does not build or push images (Cloud Run's git-integration isn't API-composable, per ticket 04's finding) — the caller must supply an already-pushed Artifact Registry image URI."
            );
        }

        ProvisioningStarted::dispatch(static::class, $repoRef, $domain);

        try {
            $tenantId = $this->tenantIdFor($domain, $repoRef);

            $this->renderer->render(
                new TenantDeployConfig(
                    tenantId: $tenantId,
                    projectId: $this->project,
                    region: $this->region,
                    network: $this->network,
                    subnetwork: $this->subnetwork,
                    image: $repoRef->image,
                    envVars: $envOverrides,
                    allowUnauthenticated: $this->allowUnauthenticated,
                ),
                $this->rootConfigDirFor($tenantId),
            );

            $applyResult = $this->tofu->apply($destinationCredential, new TofuApplyRequest($tenantId, $this->rootConfigDirFor($tenantId)));

            if (! $applyResult->successful) {
                throw new RuntimeException("CloudRunDestination::provision(): tofu apply failed for tenant `{$tenantId}`: {$this->diagnostics($applyResult)}");
            }

            $result = new ProvisionResult(
                appId: $this->serviceResourceName($tenantId),
                url: $applyResult->outputs['service_url']['value'] ?? null,
                status: 'provisioned',
                raw: $applyResult->outputs ?? [],
            );
        } catch (Throwable $e) {
            ProvisionFailed::dispatch(static::class, $repoRef, $e);

            throw $e;
        }

        Provisioned::dispatch(static::class, $result);

        return $result;
    }

    /**
     * @param  string  $appId  The full Cloud Run service resource name
     *                         (`projects/{project}/locations/{region}/services/{service}`), as returned in
     *                         {@see ProvisionResult::$appId}. The trailing segment doubles as the tenant id
     *                         this adapter derives everything else from.
     */
    public function teardown(string $destinationCredential, string $appId): void
    {
        TearingDown::dispatch(static::class, $appId);

        $tenantId = $this->tenantIdFromAppId($appId);

        $this->renderer->render(
            new TenantDeployConfig(
                tenantId: $tenantId,
                projectId: $this->project,
                region: $this->region,
                network: $this->network,
                subnetwork: $this->subnetwork,
                image: self::TEARDOWN_PLACEHOLDER_IMAGE,
                allowUnauthenticated: $this->allowUnauthenticated,
            ),
            $this->rootConfigDirFor($tenantId),
        );

        $result = $this->tofu->destroy($destinationCredential, new TofuApplyRequest($tenantId, $this->rootConfigDirFor($tenantId)));

        if (! $result->successful) {
            throw new RuntimeException("CloudRunDestination::teardown(): tofu destroy failed for tenant `{$tenantId}`: {$this->diagnostics($result)}");
        }

        TornDown::dispatch(static::class, $appId);
    }

    /**
     * @param  string  $appId  The full Cloud Run service resource name, see {@see self::teardown()}.
     */
    public function healthCheck(string $destinationCredential, string $appId): HealthResult
    {
        $service = $this->runClient($destinationCredential)->get("/{$appId}")->throw()->json();

        $reconciling = (bool) ($service['reconciling'] ?? false);
        $state = $service['terminalCondition']['state'] ?? null;

        $status = match (true) {
            $reconciling => 'unknown',
            $state === 'CONDITION_SUCCEEDED' => 'healthy',
            $state === 'CONDITION_FAILED' => 'unhealthy',
            default => 'unknown',
        };

        return new HealthResult(
            status: $status,
            url: $service['uri'] ?? null,
            raw: $service,
        );
    }

    private function tenantIdFor(string $domain, RepoRef $repoRef): string
    {
        $seed = $domain !== '' ? $domain : "{$repoRef->owner}-{$repoRef->name}";

        return Str::slug(str_replace('.', '-', $seed));
    }

    private function tenantIdFromAppId(string $appId): string
    {
        $segments = explode('/', rtrim($appId, '/'));

        return (string) end($segments);
    }

    private function serviceResourceName(string $tenantId): string
    {
        return "projects/{$this->project}/locations/{$this->region}/services/{$tenantId}";
    }

    private function rootConfigDirFor(string $tenantId): string
    {
        return rtrim($this->rootConfigsRoot, '/')."/{$tenantId}";
    }

    private function runClient(string $token): PendingRequest
    {
        return Http::withToken($token)->asJson()->acceptJson()->baseUrl(self::RUN_BASE_URL)->timeout(120);
    }

    /** Surfaces the underlying popcorn Result's error/stderr instead of losing it to a generic message. */
    private function diagnostics(TofuApplyResult $result): string
    {
        $popcorn = $result->popcornResult;

        return trim(($popcorn->error ?? 'no error message').($popcorn->stderr !== '' ? " | stderr: {$popcorn->stderr}" : ''));
    }
}
