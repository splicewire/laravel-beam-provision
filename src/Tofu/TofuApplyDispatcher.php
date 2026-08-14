<?php

namespace Splicewire\Beam\Provision\Tofu;

use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Invocables\RunnerInvocable;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Limits;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Net;

/**
 * The "apply mechanism" ticket 09 asks to package as a Popcorn capability: `tofu apply`/`destroy`
 * against ticket 07's modules + a tenant's already-rendered root config + ticket 06's per-tenant
 * state prefix, run through a {@see RunnerInvocable} so the isolation backend (today: `NullRunner`,
 * unsandboxed; later: a bwrap `Runner` with this same Grant actually enforced, or a Cloud Run
 * Jobs-backed Runner) is a binding swap, never a caller change.
 *
 * `NullRunner` over `ProcessInvocable`, per `rushing/laravel-popcorn`'s own README: "`ProcessInvocable`
 * is soft-deprecated... route all new execution work through `RunnerInvocable`." `NullRunner` *is*
 * "a `RunnerInvocable` over `NullRunner` with an empty Grant, made concrete before the seam
 * existed" — so it is the literal, un-superseded successor to what the ticket called
 * "start with a ProcessInvocable binding," not a substitution of convenience.
 */
final class TofuApplyDispatcher
{
    /** `NullRunner`'s own default (60s) is sized for quick transforms, not a real `tofu apply` against live cloud APIs — found live (ticket 10): Cloud SQL instance creation alone can take several minutes. */
    private const DEFAULT_WALL_MS = 900_000;

    public function __construct(
        private readonly Runner $runner,
        private readonly CabTokenMinter $tokenMinter,
        private readonly string $stateBucket,
        private readonly string $modulesDir,
        private readonly ?string $pluginCacheDir = null,
        private readonly int $wallMs = self::DEFAULT_WALL_MS,
    ) {}

    public function apply(string $destinationCredential, TofuApplyRequest $request): TofuApplyResult
    {
        return $this->run($destinationCredential, $request, 'apply');
    }

    public function destroy(string $destinationCredential, TofuApplyRequest $request): TofuApplyResult
    {
        return $this->run($destinationCredential, $request, 'destroy');
    }

    private function run(string $destinationCredential, TofuApplyRequest $request, string $action): TofuApplyResult
    {
        $tenantPrefix = "tenants/{$request->tenantId}/";
        $stateAccessToken = $this->tokenMinter->downscope($destinationCredential, $this->stateBucket, $tenantPrefix);

        $invocable = new RunnerInvocable(
            'gcp-tofu-apply',
            $this->runner,
            $this->manifest(),
            $this->grantFor($request),
        );

        // Two tokens, two credential surfaces (found live — see apply.php's own doc comment): the
        // broad `providerAccessToken` is what actually creates/destroys Cloud Run/Cloud SQL
        // resources; the CAB-downscoped `stateAccessToken` is scoped to nothing but this tenant's
        // own state prefix and only ever reaches the gcs backend.
        $result = $invocable->run([
            'action' => $action,
            'rootConfigDir' => $request->rootConfigDir,
            'backendBucket' => $this->stateBucket,
            'tenantId' => $request->tenantId,
            'providerAccessToken' => $destinationCredential,
            'stateAccessToken' => $stateAccessToken,
            'pluginCacheDir' => $this->pluginCacheDir,
        ]);

        return TofuApplyResult::fromPopcornResult($result);
    }

    private function manifest(): Manifest
    {
        return new Manifest(
            name: 'gcp-tofu-apply',
            runtime: 'php',
            entrypoint: dirname(__DIR__, 2).'/resources/tofu-runner/apply.php',
            description: 'Runs `tofu init` + apply/destroy for one tenant\'s rendered root config against a per-tenant GCS state prefix.',
        );
    }

    /**
     * The **requested** effective Grant — real today only in the sense that it is what a real
     * Runner (bwrap) would enforce; under `NullRunner` it is reflected in-band to the transform
     * but not kernel-enforced (documented, not silently pretended). `net: Scoped` is an honest
     * label, not the full `*.googleapis.com`-only requirement: popcorn's `Net` axis is a 3-rung
     * ladder ("egress on/off"), not a destination allowlist, until popcorn grows an egress proxy.
     * The actual domain restriction has to live one layer down, at whatever network the Runner
     * executes on (e.g. Cloud Run Jobs' VPC egress + firewall) — tracked as new fog, not solved
     * here.
     */
    private function grantFor(TofuApplyRequest $request): Grant
    {
        return new Grant(
            pathsRo: array_values(array_filter([$this->modulesDir, $this->pluginCacheDir])),
            pathsRw: [$request->rootConfigDir],
            net: Net::Scoped,
            limits: new Limits(wallMs: $this->wallMs),
        );
    }
}
