<?php

namespace Splicewire\Beam\Provision\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Provision\BeamProvisionServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BeamProvisionServiceProvider::class,
        ];
    }
}
