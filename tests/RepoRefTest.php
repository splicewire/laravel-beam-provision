<?php

use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\RepoRef;

it('throws when CloudRunDestination::provision() is called with a null connection', function () {
    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');

    $repoRef = new RepoRef(owner: 'splicewire', name: 'laravel-beam-starter', branch: 'main');

    expect(fn () => $destination->provision('fake-token', $repoRef, 'pilot.example.com'))
        ->toThrow(InvalidArgumentException::class);
});
