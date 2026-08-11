<?php

namespace Splicewire\Beam\Provision;

use Illuminate\Support\ServiceProvider;

/**
 * The provisioning-destination-seam provider. Deliberately near-empty: this package ships no
 * config file, no migrations, no routes — just the {@see Destination} interface, its two
 * adapters ({@see LaravelCloudDestination}, {@see CloudRunDestination}), the shared value
 * objects, and the lifecycle events both adapters dispatch. Nothing here needs container
 * binding — a consumer instantiates whichever adapter it wants directly, wiring its
 * constructor args (CLI binary path / GCP project+region) itself.
 */
class BeamProvisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
