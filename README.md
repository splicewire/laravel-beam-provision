# splicewire/laravel-beam-provision

A `Destination` contract for standing up a repo on a cloud provider (provision/teardown/healthCheck),
with `LaravelCloudDestination` and `CloudRunDestination` as its two adapters, proving the seam
generalizes across providers.

## The `Destination` interface

```php
namespace Splicewire\Beam\Provision;

interface Destination
{
    public function provision(string $destinationCredential, RepoRef $repoRef, string $domain, array $envOverrides = []): ProvisionResult;

    public function teardown(string $destinationCredential, string $appId): void;

    public function healthCheck(string $destinationCredential, string $appId): HealthResult;
}
```

`$destinationCredential` is always an already-resolved, ready-to-use credential string. Neither
adapter mints or resolves credentials — that happens upstream.

## Usage

```php
use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\LaravelCloudDestination;
use Splicewire\Beam\Provision\RepoRef;

// Laravel Cloud — an org-scoped API token, resolved upstream.
$laravelCloud = new LaravelCloudDestination(
    cloudBinary: '/usr/local/bin/cloud',
    cliHomeDir: '/tmp/beam-provision-cloud-home',
    defaultRegion: 'us-east-1',
);

$laravelCloud->provision(
    destinationCredential: $laravelCloudApiToken,
    repoRef: new RepoRef(owner: 'splicewire', name: 'laravel-beam-starter', branch: 'main'),
    domain: 'pilot.example.com',
);

// GCP Cloud Run — an OAuth2 access token scoped `cloud-platform`, resolved upstream.
$cloudRun = new CloudRunDestination(project: 'splicewire', region: 'us-central1');

$cloudRun->provision(
    destinationCredential: $gcpAccessToken,
    repoRef: new RepoRef(
        owner: 'splicewire',
        name: 'laravel-beam-starter',
        branch: 'main',
        connection: 'projects/splicewire/locations/us-central1/connections/splicewire-org-github',
    ),
    domain: 'pilot.example.com',
);
```

`RepoRef->connection` is required by `CloudRunDestination` — Cloud Build's git integration has no
bare-repo-URL path — and ignored by `LaravelCloudDestination`, whose git integration is
account-level. This is the one provider-specific wrinkle the `Destination` interface stayed clean
of; see `RepoRef`'s docblock.

## The apply runner, and why every result states its isolation posture

`CloudRunDestination` renders a per-tenant root config and drives `tofu apply` through a Popcorn
`RunnerInvocable`, so the **isolation backend is a binding swap, never a caller change**. Bind
`Rushing\Popcorn\Contracts\Runner` in your host and this package uses it; leave it unbound and the
container falls back to popcorn's `NullRunner`.

`NullRunner` is not a no-op — it really runs `tofu` — but it enforces nothing: the effective `Grant`
the dispatcher builds (read-only modules dir, read-write tenant config dir, `Net::Scoped`, a wall
clock) is reflected in-band to the transform and never kernel-enforced. That distinction is carried,
not dropped:

```
Rushing\Popcorn\Runner\Result::$sandboxed      false under NullRunner, true under a real substrate
  → TofuApplyResult::$sandboxed / isAdvisory()
    → ProvisionResult::$sandboxed              true | false | null
      → `splicewire:beam:provision` warns       "Apply ran UNSANDBOXED"
```

`ProvisionResult::$sandboxed` is deliberately three-state: `null` means isolation is not a concept
for that destination (`LaravelCloudDestination` drives a vendor CLI), and is spelled differently
from `false`, which is a measured "the Grant was advisory". A default of `true` would be a claim
nobody made.

**An advisory apply is fit for a single pre-agreed tenant and unfit for arbitrary customer input** —
`tofu apply` holds a broad provider credential and shells real network calls. The per-tenant *state*
isolation is separate and is enforced cryptographically either way, by `CabTokenMinter`'s
Credential-Access-Boundary downscoping of the backend token to `tenants/<id>/`.

## Scope

This package is only the `Destination` seam, its two adapters, and the lifecycle events they fire
(`ProvisioningStarted`, `Provisioned`, `ProvisionFailed`, `TearingDown`, `TornDown`). No
orchestrator, no Artisan command, no starter-forking logic, no tower integration — those are built
downstream against this contract, not inside it.
