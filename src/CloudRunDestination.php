<?php

namespace Splicewire\Beam\Provision;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Splicewire\Beam\Provision\Events\Provisioned;
use Splicewire\Beam\Provision\Events\ProvisionFailed;
use Splicewire\Beam\Provision\Events\ProvisioningStarted;
use Splicewire\Beam\Provision\Events\TearingDown;
use Splicewire\Beam\Provision\Events\TornDown;
use Throwable;

/**
 * The GCP Cloud Run {@see Destination} adapter, driven over the Cloud Run Admin API v2 and
 * (for the git-bound provisioning path) the Cloud Build API v2, both via Laravel's `Http`
 * facade. Unlike Laravel Cloud, this API's writes work fine over plain REST (confirmed by the
 * `gcp-cloud-run-provisioning` map's ticket 01 capability survey) — no CLI shell-out needed.
 * Follows the private-`client()`-factory / `->throw()` / small-per-endpoint-method style of
 * `rushing/laravel-prism-plus`'s `ReplicateClient`.
 *
 * **The flagged wrinkle this adapter is the other half of** (see {@see RepoRef}'s docblock):
 * Cloud Build's git integration has no bare-repo-URL path — `provision()` requires
 * `$repoRef->connection` to be the resource name of an already-created Cloud Build connection
 * and throws immediately if it's null, rather than silently defaulting or guessing one.
 */
class CloudRunDestination implements Destination
{
    protected const RUN_BASE_URL = 'https://run.googleapis.com/v2';

    protected const CLOUD_BUILD_BASE_URL = 'https://cloudbuild.googleapis.com/v2';

    public function __construct(
        protected string $project,
        protected string $region = 'us-central1',
    ) {}

    public function provision(string $destinationCredential, RepoRef $repoRef, string $domain, array $envOverrides = []): ProvisionResult
    {
        if ($repoRef->connection === null) {
            throw new InvalidArgumentException(
                "CloudRunDestination requires RepoRef->connection: Cloud Build's git integration has no bare-repo-URL path — it needs the resource name of an already-created Cloud Build connection (e.g. 'projects/splicewire/locations/us-central1/connections/splicewire-org-github'). The one-time GitHub App install a connection depends on is deliberately not automated by this package."
            );
        }

        ProvisioningStarted::dispatch(static::class, $repoRef, $domain);

        try {
            $triggerId = $this->createTrigger($destinationCredential, $repoRef);

            $serviceId = Str::slug(str_replace('.', '-', $domain !== '' ? $domain : "{$repoRef->owner}-{$repoRef->name}"));
            $serviceName = "projects/{$this->project}/locations/{$this->region}/services/{$serviceId}";

            // Cloud Run service creation needs a container image up front even when the real
            // build is driven by the Cloud Build trigger above — this attaches the trigger via
            // the documented `gcb-trigger-id` label (research doc §1: "An already-existing
            // Cloud Build trigger can be attached to a Cloud Run service after the fact by
            // setting a gcb-trigger-id label"), and the trigger's own first build supersedes
            // this placeholder image on its first push-triggered deploy.
            $response = $this->runClient($destinationCredential)
                ->post("projects/{$this->project}/locations/{$this->region}/services?serviceId={$serviceId}", [
                    'labels' => [
                        'gcb-trigger-id' => $triggerId,
                    ],
                    'template' => [
                        'containers' => [
                            [
                                'image' => 'us-docker.pkg.dev/cloudrun/container/hello',
                                'env' => collect($envOverrides)
                                    ->map(fn ($value, $key) => ['name' => $key, 'value' => $value])
                                    ->values()
                                    ->all(),
                            ],
                        ],
                    ],
                ])
                ->throw()
                ->json();

            // services.create returns a long-running Operation, not the finished Service — this
            // adapter deliberately does not block/poll for it to converge (that's orchestration
            // behavior, out of scope here). The service's resource name is deterministic from
            // the serviceId supplied above, so it's safe to hand back immediately; `url` is
            // unknown until the service finishes reconciling — callers use healthCheck() for that.
            $result = new ProvisionResult(
                appId: $serviceName,
                url: null,
                status: 'provisioning',
                raw: $response ?? [],
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
     *                         {@see ProvisionResult::$appId}. This method does not reconstruct that name from a bare
     *                         id — callers must pass the full resource name.
     */
    public function teardown(string $destinationCredential, string $appId): void
    {
        TearingDown::dispatch(static::class, $appId);

        $this->runClient($destinationCredential)->delete("/{$appId}")->throw();

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

    /**
     * Link `$repoRef`'s repo under its Cloud Build connection (if not already linked) and
     * create a trigger bound to `$repoRef->branch`, returning the trigger's resource name for
     * attachment to the Cloud Run service via the `gcb-trigger-id` label.
     *
     * **Unverified pending the gcp-cloud-run-provisioning map's ticket 04 live pilot** — the
     * capability survey (ticket 01) confirmed the trigger/connection/repository resource chain
     * qualitatively but explicitly flagged the exact payload shape and create-order sequencing
     * (service-first vs. trigger-first) as needing empirical verification against a real repo.
     * This is a best-faith reading of the Cloud Build v2 docs, not a guess pulled from nowhere.
     */
    private function createTrigger(string $destinationCredential, RepoRef $repoRef): string
    {
        $repositoryId = Str::slug("{$repoRef->owner}-{$repoRef->name}");

        $repository = $this->buildClient($destinationCredential)
            ->post("{$repoRef->connection}/repositories?repositoryId={$repositoryId}", [
                'remoteUri' => "https://github.com/{$repoRef->owner}/{$repoRef->name}.git",
            ])
            ->throw()
            ->json();

        $repositoryName = $repository['name'] ?? "{$repoRef->connection}/repositories/{$repositoryId}";

        $trigger = $this->buildClient($destinationCredential)
            ->post("projects/{$this->project}/locations/{$this->region}/triggers", [
                'name' => $repositoryId,
                'repositoryEventConfig' => [
                    'repository' => $repositoryName,
                    'push' => [
                        'branch' => '^'.preg_quote($repoRef->branch, '/').'$',
                    ],
                ],
                'buildpacks' => [
                    'projectDirectory' => '.',
                ],
            ])
            ->throw()
            ->json();

        return (string) ($trigger['name'] ?? $repositoryId);
    }

    private function runClient(string $token): PendingRequest
    {
        return Http::withToken($token)->asJson()->acceptJson()->baseUrl(self::RUN_BASE_URL)->timeout(120);
    }

    private function buildClient(string $token): PendingRequest
    {
        return Http::withToken($token)->asJson()->acceptJson()->baseUrl(self::CLOUD_BUILD_BASE_URL)->timeout(120);
    }
}
