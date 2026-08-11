<?php

namespace Splicewire\Beam\Provision\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Splicewire\Beam\Provision\Destination;
use Splicewire\Beam\Provision\ProvisionResult;

/**
 * A {@see Destination} adapter finished provisioning a repo
 * successfully. Dispatched right before `provision()` returns.
 */
class Provisioned
{
    use Dispatchable;

    public function __construct(
        public string $destinationClass,
        public ProvisionResult $result,
    ) {}
}
