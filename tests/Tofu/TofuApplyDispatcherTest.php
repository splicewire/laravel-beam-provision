<?php

use Illuminate\Support\Facades\Http;
use Rushing\Popcorn\Runner\Net;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;
use Splicewire\Beam\Provision\Tests\Support\RecordingRunner;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Provision\Tofu\TofuApplyRequest;

beforeEach(function () {
    Http::fake([
        'sts.googleapis.com/*' => Http::response(['access_token' => 'downscoped-token']),
    ]);
});

it('builds the Manifest, effective Grant, and input envelope ticket 09 specifies', function () {
    $runner = new RecordingRunner(Result::success(json_encode([
        'action' => 'apply',
        'changeSummary' => ['add' => 1, 'change' => 0, 'remove' => 0, 'operation' => 'apply'],
        'outputs' => ['service_url' => ['value' => 'https://pilot.example.run.app']],
    ])));

    $modulesDir = '/fake/tofu/modules';
    $pluginCacheDir = '/fake/plugin-cache';

    $dispatcher = new TofuApplyDispatcher($runner, new CabTokenMinter, 'splicewire-beam-tofu-state', $modulesDir, $pluginCacheDir);

    $request = new TofuApplyRequest(tenantId: 'tenant-a', rootConfigDir: '/fake/tenants/tenant-a');

    $result = $dispatcher->apply('broad-source-token', $request);

    expect($runner->manifest->name)->toBe('gcp-tofu-apply');
    expect($runner->manifest->runtime)->toBe('php');
    expect($runner->manifest->entrypoint)->toEndWith('resources/tofu-runner/apply.php');
    expect(is_file($runner->manifest->entrypoint))->toBeTrue();

    expect($runner->grant->pathsRw)->toBe(['/fake/tenants/tenant-a']);
    expect($runner->grant->pathsRo)->toBe([$modulesDir, $pluginCacheDir]);
    expect($runner->grant->net)->toBe(Net::Scoped);

    expect($runner->input)->toMatchArray([
        'action' => 'apply',
        'rootConfigDir' => '/fake/tenants/tenant-a',
        'backendBucket' => 'splicewire-beam-tofu-state',
        'tenantId' => 'tenant-a',
        'providerAccessToken' => 'broad-source-token',
        'stateAccessToken' => 'downscoped-token',
        'pluginCacheDir' => $pluginCacheDir,
    ]);

    expect($result->successful)->toBeTrue();
    expect($result->changeSummary)->toBe(['add' => 1, 'change' => 0, 'remove' => 0, 'operation' => 'apply']);
    expect($result->isNoOp())->toBeFalse();
});

it('recognizes a no-op re-apply from the change summary', function () {
    $runner = new RecordingRunner(Result::success(json_encode([
        'action' => 'apply',
        'changeSummary' => ['add' => 0, 'change' => 0, 'remove' => 0, 'operation' => 'apply'],
        'outputs' => [],
    ])));

    $dispatcher = new TofuApplyDispatcher($runner, new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules');

    $result = $dispatcher->apply('broad-source-token', new TofuApplyRequest('tenant-a', '/fake/tenants/tenant-a'));

    expect($result->isNoOp())->toBeTrue();
});

it('dispatches destroy with the destroy action', function () {
    $runner = new RecordingRunner(Result::success(json_encode([
        'action' => 'destroy',
        'changeSummary' => ['add' => 0, 'change' => 0, 'remove' => 2, 'operation' => 'destroy'],
        'outputs' => [],
    ])));

    $dispatcher = new TofuApplyDispatcher($runner, new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules');

    $dispatcher->destroy('broad-source-token', new TofuApplyRequest('tenant-a', '/fake/tenants/tenant-a'));

    expect($runner->input['action'])->toBe('destroy');
});

it('propagates a failed popcorn Result without pretending a change summary exists', function () {
    $runner = new RecordingRunner(new Result(Outcome::NonZeroExit, error: 'tofu apply failed'));

    $dispatcher = new TofuApplyDispatcher($runner, new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules');

    $result = $dispatcher->apply('broad-source-token', new TofuApplyRequest('tenant-a', '/fake/tenants/tenant-a'));

    expect($result->successful)->toBeFalse();
    expect($result->changeSummary)->toBeNull();
    expect($result->isNoOp())->toBeFalse();
});
