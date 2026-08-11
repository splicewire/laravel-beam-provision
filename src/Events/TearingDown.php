<?php

namespace Splicewire\Beam\Provision\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Splicewire\Beam\Provision\Destination;

/**
 * A {@see Destination} adapter began tearing down an app. Dispatched
 * at the top of `teardown()`.
 */
class TearingDown
{
    use Dispatchable;

    public function __construct(
        public string $destinationClass,
        public string $appId,
    ) {}
}
