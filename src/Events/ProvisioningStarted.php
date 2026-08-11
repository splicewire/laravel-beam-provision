<?php

namespace Splicewire\Beam\Provision\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Splicewire\Beam\Provision\Destination;
use Splicewire\Beam\Provision\RepoRef;

/**
 * A {@see Destination} adapter began provisioning a repo. Dispatched
 * at the top of `provision()`, before any provider call is made.
 */
class ProvisioningStarted
{
    use Dispatchable;

    public function __construct(
        public string $destinationClass,
        public RepoRef $repoRef,
        public string $domain,
    ) {}
}
