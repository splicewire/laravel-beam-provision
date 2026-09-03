<?php

use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\ProvisionResult;
use Splicewire\Beam\Provision\RepoRef;

it('provisions via the bound CloudRunDestination and prints the result', function () {
    $destination = Mockery::mock(CloudRunDestination::class);
    $destination->shouldReceive('provision')
        ->once()
        ->with(
            'a-token',
            Mockery::on(fn (RepoRef $r) => $r->image === 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123'),
            'pilot.example.com',
            ['FOO' => 'bar'],
        )
        ->andReturn(new ProvisionResult(
            appId: 'projects/splicewire/locations/us-central1/services/pilot-example-com',
            url: 'https://pilot.example.com',
            status: 'provisioned',
        ));

    $this->app->instance(CloudRunDestination::class, $destination);

    $this->artisan('splicewire:beam:provision', [
        'domain' => 'pilot.example.com',
        'image' => 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123',
        '--token' => 'a-token',
        '--set-env' => ['FOO=bar'],
    ])->assertExitCode(0);
});

it('falls back to the GOOGLE_ACCESS_TOKEN env var when --token is omitted', function () {
    $destination = Mockery::mock(CloudRunDestination::class);
    $destination->shouldReceive('provision')
        ->once()
        ->with('env-token', Mockery::type(RepoRef::class), 'pilot.example.com', [])
        ->andReturn(new ProvisionResult(appId: 'x', url: null, status: 'provisioned'));

    $this->app->instance(CloudRunDestination::class, $destination);

    putenv('GOOGLE_ACCESS_TOKEN=env-token');

    try {
        $this->artisan('splicewire:beam:provision', [
            'domain' => 'pilot.example.com',
            'image' => 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123',
        ])->assertExitCode(0);
    } finally {
        putenv('GOOGLE_ACCESS_TOKEN');
    }
});

it('fails fast without a token', function () {
    putenv('GOOGLE_ACCESS_TOKEN');

    $this->artisan('splicewire:beam:provision', [
        'domain' => 'pilot.example.com',
        'image' => 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123',
    ])->assertExitCode(1);
});

it('warns the operator when the apply ran with an advisory Grant, and stays silent when it did not', function (?bool $sandboxed, bool $expectWarning) {
    $destination = Mockery::mock(CloudRunDestination::class);
    $destination->shouldReceive('provision')
        ->once()
        ->andReturn(new ProvisionResult(appId: 'x', url: 'https://x.run.app', status: 'provisioned', sandboxed: $sandboxed));

    $this->app->instance(CloudRunDestination::class, $destination);

    $command = $this->artisan('splicewire:beam:provision', [
        'domain' => 'pilot.example.com',
        'image' => 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123',
        '--token' => 'a-token',
    ]);

    $expectWarning
        ? $command->expectsOutputToContain('UNSANDBOXED')
        : $command->doesntExpectOutputToContain('UNSANDBOXED');

    $command->assertExitCode(0);
})->with([
    'advisory Grant — the operator must be told' => [false, true],
    'enforced by a real substrate' => [true, false],
    'not a Runner-backed destination at all' => [null, false],
]);
