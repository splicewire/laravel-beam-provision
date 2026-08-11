<?php

namespace Splicewire\Beam\Provision;

/**
 * The provisioning-destination seam: stand up (and tear down, and check on) a repo on some
 * cloud provider. `Destination` doesn't know what a "starter" is — it stands up whatever repo
 * it's handed via {@see RepoRef}. Orchestration (repo-forking, env-wiring policy, an Artisan
 * trigger surface, tower integration) is deliberately not this interface's job — that's
 * downstream, built against implementations of this contract, not inside them.
 *
 * `$destinationCredential` is always an already-resolved, ready-to-use credential string (a
 * Laravel Cloud org-scoped API token, or a GCP OAuth2 `cloud-platform`-scoped access token).
 * No implementation of this interface is responsible for minting or resolving credentials —
 * impersonation, token refresh, and the provider's own login flow all happen upstream.
 */
interface Destination
{
    /**
     * Stand up `$repoRef` at `$domain` on this destination.
     *
     * @param  array<string, string>  $envOverrides
     */
    public function provision(string $destinationCredential, RepoRef $repoRef, string $domain, array $envOverrides = []): ProvisionResult;

    public function teardown(string $destinationCredential, string $appId): void;

    public function healthCheck(string $destinationCredential, string $appId): HealthResult;
}
