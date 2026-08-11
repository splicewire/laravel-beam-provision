<?php

use Splicewire\Beam\Provision\BeamProvisionServiceProvider;

it('registers and boots without error', function () {
    expect(app()->getProviders(BeamProvisionServiceProvider::class))->not->toBeEmpty();
});
