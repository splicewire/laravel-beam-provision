<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\Events\Provisioned;
use Splicewire\Beam\Provision\Events\ProvisionFailed;
use Splicewire\Beam\Provision\Events\ProvisioningStarted;
use Splicewire\Beam\Provision\Events\TearingDown;
use Splicewire\Beam\Provision\Events\TornDown;
use Splicewire\Beam\Provision\HealthResult;
use Splicewire\Beam\Provision\ProvisionResult;
use Splicewire\Beam\Provision\RepoRef;

function makeRepoRef(): RepoRef
{
    return new RepoRef(
        owner: 'splicewire',
        name: 'laravel-beam-starter',
        branch: 'main',
        connection: 'projects/splicewire/locations/us-central1/connections/splicewire-org-github',
    );
}

it('provision() hits the expected Cloud Build and Cloud Run endpoints on the happy path', function () {
    Http::fake([
        'cloudbuild.googleapis.com/*' => Http::response(['name' => 'fake-trigger-resource'], 200),
        'run.googleapis.com/*' => Http::response(['name' => 'operations/fake'], 200),
    ]);

    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');

    $result = $destination->provision('fake-token', makeRepoRef(), 'pilot.example.com', ['FOO' => 'bar']);

    expect($result)->toBeInstanceOf(ProvisionResult::class)
        ->and($result->appId)->toBe('projects/splicewire/locations/us-central1/services/pilot-example-com')
        ->and($result->status)->toBe('provisioning');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), 'cloudbuild.googleapis.com')
        && str_contains($request->url(), 'repositories'));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), 'cloudbuild.googleapis.com')
        && str_contains($request->url(), 'triggers'));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), 'run.googleapis.com')
        && str_contains($request->url(), 'services?serviceId=pilot-example-com'));
});

it('teardown() DELETEs the given service resource name', function () {
    Http::fake([
        'run.googleapis.com/*' => Http::response([], 200),
    ]);

    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');

    $destination->teardown('fake-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), 'projects/splicewire/locations/us-central1/services/pilot-example-com'));
});

it('healthCheck() maps terminalCondition/reconciling/uri into a HealthResult', function () {
    Http::fake([
        'run.googleapis.com/*' => Http::response([
            'terminalCondition' => ['state' => 'CONDITION_SUCCEEDED'],
            'reconciling' => false,
            'uri' => 'https://pilot-example-com-abc123.run.app',
        ], 200),
    ]);

    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');

    $result = $destination->healthCheck('fake-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    expect($result)->toBeInstanceOf(HealthResult::class)
        ->and($result->status)->toBe('healthy')
        ->and($result->url)->toBe('https://pilot-example-com-abc123.run.app');
});

it('dispatches ProvisioningStarted and Provisioned on a successful provision()', function () {
    Event::fake([ProvisioningStarted::class, Provisioned::class, ProvisionFailed::class]);
    Http::fake([
        'cloudbuild.googleapis.com/*' => Http::response(['name' => 'fake-trigger-resource'], 200),
        'run.googleapis.com/*' => Http::response(['name' => 'operations/fake'], 200),
    ]);

    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');
    $destination->provision('fake-token', makeRepoRef(), 'pilot.example.com');

    Event::assertDispatched(ProvisioningStarted::class);
    Event::assertDispatched(Provisioned::class);
    Event::assertNotDispatched(ProvisionFailed::class);
});

it('dispatches ProvisionFailed and rethrows when the HTTP call fails', function () {
    Event::fake([ProvisioningStarted::class, Provisioned::class, ProvisionFailed::class]);
    Http::fake([
        'cloudbuild.googleapis.com/*' => Http::response(['error' => 'boom'], 500),
    ]);

    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');

    expect(fn () => $destination->provision('fake-token', makeRepoRef(), 'pilot.example.com'))
        ->toThrow(RequestException::class);

    Event::assertDispatched(ProvisioningStarted::class);
    Event::assertDispatched(ProvisionFailed::class);
    Event::assertNotDispatched(Provisioned::class);
});

it('dispatches TearingDown and TornDown around teardown()', function () {
    Event::fake([TearingDown::class, TornDown::class]);
    Http::fake([
        'run.googleapis.com/*' => Http::response([], 200),
    ]);

    $destination = new CloudRunDestination(project: 'splicewire', region: 'us-central1');
    $destination->teardown('fake-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    Event::assertDispatched(TearingDown::class);
    Event::assertDispatched(TornDown::class);
});
