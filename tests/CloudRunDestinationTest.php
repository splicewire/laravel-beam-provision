<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;
use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\Events\Provisioned;
use Splicewire\Beam\Provision\Events\ProvisionFailed;
use Splicewire\Beam\Provision\Events\ProvisioningStarted;
use Splicewire\Beam\Provision\Events\TearingDown;
use Splicewire\Beam\Provision\Events\TornDown;
use Splicewire\Beam\Provision\HealthResult;
use Splicewire\Beam\Provision\ProvisionResult;
use Splicewire\Beam\Provision\RepoRef;
use Splicewire\Beam\Provision\Tests\Support\RecordingRunner;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TenantRootConfigRenderer;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;

function makeRepoRef(?string $image = 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123'): RepoRef
{
    return new RepoRef(
        owner: 'splicewire',
        name: 'beam-pilot-gcp-cloud-run',
        branch: 'main',
        image: $image,
    );
}

/** @return array{0: CloudRunDestination, 1: RecordingRunner, 2: string} */
function makeCloudRunDestination(Result $toReturn): array
{
    $runner = new RecordingRunner($toReturn);
    $rootConfigsRoot = sys_get_temp_dir().'/beam-tofu-destination-test-'.uniqid();

    $destination = new CloudRunDestination(
        project: 'splicewire',
        region: 'us-central1',
        network: 'projects/splicewire/global/networks/beam-tenant-network',
        subnetwork: 'beam-tenant-subnet-us-central1',
        tofu: new TofuApplyDispatcher($runner, new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules'),
        renderer: new TenantRootConfigRenderer('/fake/tofu/modules'),
        rootConfigsRoot: $rootConfigsRoot,
    );

    return [$destination, $runner, $rootConfigsRoot];
}

beforeEach(function () {
    Http::fake(['sts.googleapis.com/*' => Http::response(['access_token' => 'downscoped-token'])]);
});

it('provision() renders the tenant root config and applies it via the Tofu dispatcher', function () {
    [$destination, $runner, $rootConfigsRoot] = makeCloudRunDestination(Result::success(json_encode([
        'action' => 'apply',
        'changeSummary' => ['add' => 4, 'change' => 0, 'remove' => 0, 'operation' => 'apply'],
        'outputs' => ['service_url' => ['value' => 'https://pilot-example-com-abc123.run.app']],
    ])));

    $result = $destination->provision('broad-token', makeRepoRef(), 'pilot.example.com', ['APP_ENV' => 'production']);

    expect($result)->toBeInstanceOf(ProvisionResult::class)
        ->and($result->appId)->toBe('projects/splicewire/locations/us-central1/services/pilot-example-com')
        ->and($result->url)->toBe('https://pilot-example-com-abc123.run.app')
        ->and($result->status)->toBe('provisioned');

    expect($runner->input['action'])->toBe('apply');
    expect($runner->input['tenantId'])->toBe('pilot-example-com');
    expect($runner->input['rootConfigDir'])->toBe("{$rootConfigsRoot}/pilot-example-com");

    $rendered = json_decode(file_get_contents("{$rootConfigsRoot}/pilot-example-com/main.tf.json"), true);
    expect($rendered['module']['cloud_run_service']['image'])->toBe('us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123');
    expect($rendered['module']['cloud_run_service']['env_vars'])->toBe(['APP_ENV' => 'production']);
});

it('provision() refuses a RepoRef with no image — this adapter does not build one', function () {
    [$destination] = makeCloudRunDestination(Result::success(json_encode(['action' => 'apply', 'changeSummary' => null, 'outputs' => []])));

    expect(fn () => $destination->provision('broad-token', makeRepoRef(image: null), 'pilot.example.com'))
        ->toThrow(InvalidArgumentException::class, 'requires RepoRef->image');
});

it('provision() throws when tofu apply fails, without a fake successful ProvisionResult', function () {
    [$destination] = makeCloudRunDestination(new Result(Outcome::NonZeroExit, error: 'tofu apply failed'));

    expect(fn () => $destination->provision('broad-token', makeRepoRef(), 'pilot.example.com'))
        ->toThrow(RuntimeException::class, 'tofu apply failed for tenant');
});

it('dispatches ProvisioningStarted and Provisioned on a successful provision()', function () {
    Event::fake([ProvisioningStarted::class, Provisioned::class, ProvisionFailed::class]);
    [$destination] = makeCloudRunDestination(Result::success(json_encode([
        'action' => 'apply', 'changeSummary' => ['add' => 1, 'change' => 0, 'remove' => 0, 'operation' => 'apply'], 'outputs' => [],
    ])));

    $destination->provision('broad-token', makeRepoRef(), 'pilot.example.com');

    Event::assertDispatched(ProvisioningStarted::class);
    Event::assertDispatched(Provisioned::class);
    Event::assertNotDispatched(ProvisionFailed::class);
});

it('dispatches ProvisionFailed and rethrows when the apply fails', function () {
    Event::fake([ProvisioningStarted::class, Provisioned::class, ProvisionFailed::class]);
    [$destination] = makeCloudRunDestination(new Result(Outcome::NonZeroExit, error: 'boom'));

    expect(fn () => $destination->provision('broad-token', makeRepoRef(), 'pilot.example.com'))->toThrow(RuntimeException::class);

    Event::assertDispatched(ProvisioningStarted::class);
    Event::assertDispatched(ProvisionFailed::class);
    Event::assertNotDispatched(Provisioned::class);
});

it('teardown() re-renders the tenant config from the appId and calls destroy', function () {
    [$destination, $runner, $rootConfigsRoot] = makeCloudRunDestination(Result::success(json_encode([
        'action' => 'destroy', 'changeSummary' => ['add' => 0, 'change' => 0, 'remove' => 4, 'operation' => 'destroy'], 'outputs' => [],
    ])));

    $destination->teardown('broad-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    expect($runner->input['action'])->toBe('destroy');
    expect($runner->input['tenantId'])->toBe('pilot-example-com');
    expect("{$rootConfigsRoot}/pilot-example-com/main.tf.json")->toBeFile();
});

it('teardown() throws when tofu destroy fails', function () {
    [$destination] = makeCloudRunDestination(new Result(Outcome::NonZeroExit, error: 'destroy failed'));

    expect(fn () => $destination->teardown('broad-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com'))
        ->toThrow(RuntimeException::class, 'tofu destroy failed for tenant');
});

it('dispatches TearingDown and TornDown around a successful teardown()', function () {
    Event::fake([TearingDown::class, TornDown::class]);
    [$destination] = makeCloudRunDestination(Result::success(json_encode([
        'action' => 'destroy', 'changeSummary' => ['add' => 0, 'change' => 0, 'remove' => 4, 'operation' => 'destroy'], 'outputs' => [],
    ])));

    $destination->teardown('broad-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    Event::assertDispatched(TearingDown::class);
    Event::assertDispatched(TornDown::class);
});

it('healthCheck() maps terminalCondition/reconciling/uri into a HealthResult, unchanged from the raw-REST path', function () {
    Http::fake([
        'run.googleapis.com/*' => Http::response([
            'terminalCondition' => ['state' => 'CONDITION_SUCCEEDED'],
            'reconciling' => false,
            'uri' => 'https://pilot-example-com-abc123.run.app',
        ], 200),
    ]);
    [$destination] = makeCloudRunDestination(Result::success('{}'));

    $result = $destination->healthCheck('broad-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    expect($result)->toBeInstanceOf(HealthResult::class)
        ->and($result->status)->toBe('healthy')
        ->and($result->url)->toBe('https://pilot-example-com-abc123.run.app');
});

it('healthCheck() does not touch the Tofu dispatcher at all — cheap read, no apply', function () {
    Http::fake([
        'run.googleapis.com/*' => Http::response(['reconciling' => true], 200),
    ]);
    [$destination, $runner] = makeCloudRunDestination(Result::success('{}'));

    $destination->healthCheck('broad-token', 'projects/splicewire/locations/us-central1/services/pilot-example-com');

    expect($runner->input)->toBeNull();
});
