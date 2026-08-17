<?php

use Splicewire\Beam\Provision\BeamProvisionServiceProvider;
use Splicewire\Beam\Provision\CloudRunDestination;

it('registers and boots without error', function () {
    expect(app()->getProviders(BeamProvisionServiceProvider::class))->not->toBeEmpty();
});

it('resolves the real CloudRunDestination container binding, not a mock', function () {
    // Deliberately NOT bound to an instance/mock elsewhere — this exercises the actual factory
    // closure (NullRunner/CabTokenMinter/TofuApplyDispatcher construction), the one thing
    // ProvisionCommandTest's mocked container binding can never catch a break in.
    expect(app(CloudRunDestination::class))->toBeInstanceOf(CloudRunDestination::class);
});
