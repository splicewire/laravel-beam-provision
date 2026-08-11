<?php

namespace Splicewire\Beam\Provision;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Splicewire\Beam\Provision\Events\Provisioned;
use Splicewire\Beam\Provision\Events\ProvisionFailed;
use Splicewire\Beam\Provision\Events\ProvisioningStarted;
use Splicewire\Beam\Provision\Events\TearingDown;
use Splicewire\Beam\Provision\Events\TornDown;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Laravel Cloud {@see Destination} adapter, driven exclusively via the official `cloud`
 * CLI.
 *
 * **Load-bearing constraint** (already discovered empirically against the real Laravel Cloud
 * org, `laravel-cloud-provisioning` map ticket 04, first proven in code at
 * `splicewire/laravel-beam-tenancy`'s `Destinations\IsolatedDatabaseDestination`): raw HTTP
 * writes against Laravel Cloud's REST API silently redirect to the dashboard instead of
 * writing. Only the official `cloud` CLI performs writes. `GET`s do work fine over
 * bearer-token HTTP, but this class doesn't need any — every operation here is a write — so it
 * shells out to the CLI exclusively rather than splitting across two transports.
 *
 * The `runCli()`/`ensureCliAuth()`/`argsForError()` shape below is copied near-verbatim from
 * `IsolatedDatabaseDestination`, adapted from database-cluster commands to application-level
 * ones, and adapted so the API token is a per-call parameter (this class stores no credential
 * on the instance — {@see Destination} passes `$destinationCredential` per method call).
 */
class LaravelCloudDestination implements Destination
{
    /**
     * @param  string  $cloudBinary  Absolute path to (or PATH-resolvable name of) the `cloud` CLI.
     * @param  string  $cliHomeDir  A dedicated, process-owned HOME directory the CLI's
     *                              `~/.config/cloud/config.json` auth file is materialized under, so this class never
     *                              touches a real developer's home directory.
     * @param  string  $defaultRegion  Laravel Cloud region slug used for `application:create`.
     */
    public function __construct(
        protected string $cloudBinary,
        protected string $cliHomeDir,
        protected string $defaultRegion = 'us-east-1',
    ) {}

    public function provision(string $destinationCredential, RepoRef $repoRef, string $domain, array $envOverrides = []): ProvisionResult
    {
        ProvisioningStarted::dispatch(static::class, $repoRef, $domain);

        try {
            $name = Str::slug(str_replace('.', '-', $domain !== '' ? $domain : "{$repoRef->owner}-{$repoRef->name}"));

            $created = $this->runCli($destinationCredential, [
                'application:create',
                "--repository={$repoRef->owner}/{$repoRef->name}",
                "--name={$name}",
                "--region={$this->defaultRegion}",
                '--json',
            ]);

            $appId = (string) ($created['id'] ?? throw new RuntimeException("Laravel Cloud application:create for '{$name}' returned no application id."));

            // Flag: the exact `environment:variables` subcommand/flag shape is unverified —
            // best-guess form per the closed laravel-cloud-provisioning map's ticket 01 CLI
            // survey. Left for ticket 04's live pilot to empirically correct; not a blocker here.
            foreach ($envOverrides as $key => $value) {
                $this->runCli($destinationCredential, [
                    'environment:variables:set',
                    '--environment=production',
                    "--key={$key}",
                    "--value={$value}",
                    '--json',
                ]);
            }

            if ($domain !== '') {
                $this->runCli($destinationCredential, [
                    'domain:create',
                    '--environment=production',
                    "--domain={$domain}",
                    '--json',
                ]);
            }

            $deployed = $this->runCli($destinationCredential, [
                'deploy',
                '--environment=production',
                '--json',
            ]);

            $result = new ProvisionResult(
                appId: $appId,
                url: $created['url'] ?? $created['domain'] ?? ($domain !== '' ? $domain : null),
                status: (string) ($deployed['status'] ?? 'provisioning'),
                raw: ['create' => $created, 'deploy' => $deployed],
            );
        } catch (Throwable $e) {
            ProvisionFailed::dispatch(static::class, $repoRef, $e);

            throw $e;
        }

        Provisioned::dispatch(static::class, $result);

        return $result;
    }

    public function teardown(string $destinationCredential, string $appId): void
    {
        TearingDown::dispatch(static::class, $appId);

        $this->runCli($destinationCredential, ['application:delete', $appId, '--force', '--json']);

        TornDown::dispatch(static::class, $appId);
    }

    public function healthCheck(string $destinationCredential, string $appId): HealthResult
    {
        $shown = $this->runCli($destinationCredential, ['application:show', $appId, '--json']);

        $status = (string) ($shown['status'] ?? 'unknown');

        return new HealthResult(
            status: match (true) {
                Str::contains($status, 'active') || Str::contains($status, 'running') => 'healthy',
                Str::contains($status, 'fail') || Str::contains($status, 'error') => 'unhealthy',
                default => 'unknown',
            },
            url: $shown['url'] ?? $shown['domain'] ?? null,
            raw: $shown,
        );
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<string, mixed>
     */
    protected function runCli(string $apiToken, array $arguments): array
    {
        $this->ensureCliAuth($apiToken);

        $process = new Process(
            [$this->cloudBinary, ...$arguments, '-n'],
            env: ['HOME' => $this->cliHomeDir],
            timeout: 120,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Laravel Cloud CLI command failed: `cloud {$this->argsForError($arguments)}` — ".trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        $decoded = json_decode($process->getOutput(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Laravel Cloud CLI returned non-JSON output for `cloud {$this->argsForError($arguments)}`: ".$process->getOutput());
        }

        if (($decoded['error'] ?? false) === true) {
            throw new RuntimeException('Laravel Cloud CLI error: '.($decoded['message'] ?? json_encode($decoded)));
        }

        return $decoded;
    }

    protected function argsForError(array $arguments): string
    {
        return implode(' ', array_filter($arguments, fn ($a) => ! Str::contains($a, 'password')));
    }

    /**
     * Materialize the CLI's own config-file auth (its only auth path — no env var) under a
     * dedicated HOME this process controls, so this class never depends on (or clobbers) a
     * real developer's `~/.config/cloud/config.json`.
     */
    protected function ensureCliAuth(string $apiToken): void
    {
        $configDir = "{$this->cliHomeDir}/.config/cloud";
        $configFile = "{$configDir}/config.json";

        if (File::exists($configFile)) {
            $existing = json_decode(File::get($configFile), true);
            if (($existing['api_tokens'][0] ?? null) === $apiToken) {
                return;
            }
        }

        File::ensureDirectoryExists($configDir);
        File::put($configFile, json_encode(['api_tokens' => [$apiToken]], JSON_PRETTY_PRINT));
    }
}
