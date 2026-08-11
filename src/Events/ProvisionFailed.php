<?php

namespace Splicewire\Beam\Provision\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Splicewire\Beam\Provision\Destination;
use Splicewire\Beam\Provision\RepoRef;
use Throwable;

/**
 * A {@see Destination} adapter's `provision()` call threw. Dispatched
 * from the catch block wrapping the provision logic, then the exception is rethrown — this event
 * is a notification, not an error-handling seam.
 */
class ProvisionFailed
{
    use Dispatchable;

    public function __construct(
        public string $destinationClass,
        public RepoRef $repoRef,
        public Throwable $exception,
    ) {}
}
