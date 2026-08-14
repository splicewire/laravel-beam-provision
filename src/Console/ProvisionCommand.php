<?php

declare(strict_types=1);

namespace Splicewire\Beam\Provision\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\RepoRef;

/**
 * The CI trigger surface `Destination.php` calls out as deliberately not the interface's own job:
 * "downstream, built against implementations of this contract, not inside them." This command IS
 * that downstream — a thin composition of the already-built mechanism (render + apply, ticket
 * 09/10), not new provisioning logic. It provisions the app *and* its tenant database in one call:
 * TenantRootConfigRenderer bundles both modules into a tenant's single rendered config regardless
 * of caller.
 *
 * The GCP access token is always caller-supplied (`--token`, or the `GOOGLE_ACCESS_TOKEN` env var
 * a CI step exports) — this command never mints or refreshes credentials itself, matching
 * `Destination::provision()`'s own existing contract.
 */
class ProvisionCommand extends Command
{
    protected $signature = 'splicewire:beam:provision
        {domain : The tenant domain — also seeds the tenant/service id}
        {image : A pre-built, already-pushed Artifact Registry image URI}
        {--token= : GCP access token; falls back to the GOOGLE_ACCESS_TOKEN env var}
        {--owner= : Repo owner, for audit/event context only}
        {--name= : Repo name, for audit/event context only}
        {--branch=main : Repo branch, for audit/event context only}
        {--set-env=* : Env var overrides for the deployed service, KEY=VALUE}';

    protected $description = 'Provision (or update) a tenant on Cloud Run + its tenant database via CloudRunDestination.';

    public function handle(CloudRunDestination $destination): int
    {
        $token = $this->option('token') ?: getenv('GOOGLE_ACCESS_TOKEN') ?: null;

        if (! $token) {
            $this->error('No GCP access token: pass --token or set GOOGLE_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        $repoRef = new RepoRef(
            owner: (string) $this->option('owner'),
            name: (string) $this->option('name'),
            branch: (string) $this->option('branch'),
            image: (string) $this->argument('image'),
        );

        try {
            $result = $destination->provision($token, $repoRef, (string) $this->argument('domain'), $this->envOverrides());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Provisioned: {$result->appId}");
        $this->info("URL: {$result->url}");

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function envOverrides(): array
    {
        $overrides = [];

        foreach ($this->option('set-env') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $overrides[$key] = $value;
        }

        return $overrides;
    }
}
