<?php

namespace Splicewire\Beam\Provision;

use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Laravel\Runner\NullRunner;
use Splicewire\Beam\Provision\Console\ProvisionCommand;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TenantRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Provision\Tofu\TofuModulesPath;

/**
 * The provisioning-destination-seam provider. Ships one opt-in config file
 * (`config/beam/provision.php`) plus a container binding for {@see CloudRunDestination} and the
 * {@see ProvisionCommand} that composes it — both new (gcp-cloud-run-provisioning map, the
 * "how do I go from zero to CI auto-deploy" follow-on). The {@see Destination} interface and its
 * adapters remain consumer-instantiable directly, unchanged; this binding is a convenience for the
 * one concrete downstream consumer that exists today (the CLI trigger), not a requirement of the
 * contract itself — Destination.php's "an Artisan trigger is downstream, not this interface's job"
 * still holds, this provider just IS that downstream, living in the same package as the mechanism
 * (TofuApplyDispatcher/TenantRootConfigRenderer) it composes.
 */
class BeamProvisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam/provision.php', 'beam.provision');

        $this->app->singleton(CloudRunDestination::class, function ($app) {
            $config = $app['config']->get('beam.provision');
            $modulesDir = TofuModulesPath::dir();

            $tofu = new TofuApplyDispatcher(
                // The isolation backend is a **binding swap, never a caller change** — the whole
                // point of routing the apply through a `RunnerInvocable`. A host that installs a
                // real substrate (bwrap via `laravel-popcorn-bubble`, or a Cloud Run Jobs Runner)
                // binds `Runner::class` and this package needs no edit. Unbound, the honest local
                // fallback is `NullRunner`: it really runs `tofu`, it just enforces nothing — and
                // it says so, via `Result::$sandboxed` → `TofuApplyResult::isAdvisory()` →
                // `ProvisionResult::$sandboxed`.
                runner: $app->bound(Runner::class) ? $app->make(Runner::class) : new NullRunner,
                tokenMinter: new CabTokenMinter,
                stateBucket: $config['state_bucket'],
                modulesDir: $modulesDir,
                pluginCacheDir: $config['plugin_cache_dir'],
                wallMs: $config['apply_wall_ms'],
            );

            return new CloudRunDestination(
                project: $config['project'],
                region: $config['region'],
                network: $config['network'],
                subnetwork: $config['subnetwork'],
                tofu: $tofu,
                renderer: new TenantRootConfigRenderer($modulesDir),
                rootConfigsRoot: $config['root_configs_root'],
                allowUnauthenticated: $config['allow_unauthenticated'],
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam/provision.php' => config_path('beam/provision.php'),
            ], 'beam-provision-config');

            // The container entrypoint. Published here rather than hand-copied per host because this
            // package owns the Cloud Run deploy contract the entrypoint is the other half of.
            //
            // ⚠️ It exists because three copies happened to agree and nothing kept them agreeing:
            // measured 2026-09-09, all three starters' `docker/entrypoint.sh` were byte-identical
            // (md5 304eb9c9…) and all three ran `exec php artisan serve` — the single-threaded dev
            // server — in production. Every host cut from a starter inherited it.
            //
            // Published, not vendored, deliberately: a host may need to edit its own boot sequence, and
            // a file it cannot edit would be worked around rather than used. The cost is that a
            // published copy can drift from this stub, which is what
            // `PublishedMigrationDriftAudit` already reports for migrations — byte-compare before
            // assuming a divergence is staleness rather than a deliberate host edit.
            $this->publishes([
                __DIR__.'/../resources/docker/entrypoint.sh.stub' => base_path('docker/entrypoint.sh'),
            ], 'beam-provision-docker');

            $this->commands([
                ProvisionCommand::class,
            ]);
        }
    }
}
