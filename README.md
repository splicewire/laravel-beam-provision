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

## Scope

This package is only the `Destination` seam, its two adapters, and the lifecycle events they fire
(`ProvisioningStarted`, `Provisioned`, `ProvisionFailed`, `TearingDown`, `TornDown`). No
orchestrator, no Artisan command, no starter-forking logic, no tower integration — those are built
downstream against this contract, not inside it.
