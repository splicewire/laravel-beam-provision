<?php

namespace Splicewire\Beam\Provision;

use Illuminate\Support\ServiceProvider;
use Rushing\Popcorn\Laravel\Runner\NullRunner;
use Splicewire\Beam\Provision\Console\ProvisionCommand;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TenantRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;

/**
 * The provisioning-destination-seam provider. Ships one opt-in config file
 * (`config/beam-provision.php`) plus a container binding for {@see CloudRunDestination} and the
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
        $this->mergeConfigFrom(__DIR__.'/../config/beam-provision.php', 'beam-provision');

        $this->app->singleton(CloudRunDestination::class, function ($app) {
            $config = $app['config']->get('beam-provision');
            $modulesDir = dirname(__DIR__).'/tofu/modules';

            $tofu = new TofuApplyDispatcher(
                runner: new NullRunner,
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
                __DIR__.'/../config/beam-provision.php' => config_path('beam-provision.php'),
            ], 'beam-provision-config');

            $this->commands([
                ProvisionCommand::class,
            ]);
        }
    }
}
