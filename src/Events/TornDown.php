<?php

namespace Splicewire\Beam\Provision\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Splicewire\Beam\Provision\Destination;

/**
 * A {@see Destination} adapter finished tearing down an app
 * successfully. Dispatched at the end of `teardown()`.
 */
class TornDown
{
    use Dispatchable;

    public function __construct(
        public string $destinationClass,
        public string $appId,
    ) {}
}
