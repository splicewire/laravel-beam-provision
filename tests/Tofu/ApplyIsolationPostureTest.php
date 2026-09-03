<?php

/**
 * The apply half's isolation posture has to reach the caller.
 *
 * `NullRunner` really runs `tofu` — it is not a no-op — but it enforces **nothing**: the effective
 * `Grant` the dispatcher builds is reflected in-band to the transform and never kernel-enforced,
 * which is exactly why popcorn's own `Result::$sandboxed` is hard-`false` there. Until this test
 * existed that flag stopped at the popcorn `Result`: `TofuApplyResult` dropped it, and every layer
 * above (`CloudRunDestination`, `ProvisionResult`, `splicewire:beam:provision`) reported an
 * advisory-Grant apply and a kernel-enforced one with byte-identical output.
 *
 * That is this estate's signature defect in its exact canonical form (`AGENTS.md`, "an instrument
 * that reports success by not running"): the answer could not distinguish *enforced* from *not
 * enforced*. The repair is consumption, not vocabulary — the value was already on the wire.
 */

use Illuminate\Support\Facades\Http;
use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\Outcome;
use Rushing\Popcorn\Runner\Result;
use Splicewire\Beam\Provision\CloudRunDestination;
use Splicewire\Beam\Provision\RepoRef;
use Splicewire\Beam\Provision\Tests\Support\RecordingRunner;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;
use Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher;
use Splicewire\Beam\Provision\Tofu\TofuApplyRequest;
use Splicewire\Beam\Provision\Tofu\TofuApplyResult;

/** What a `NullRunner` actually hands back on a successful run: a real success, `sandboxed: false`. */
function unsandboxedSuccess(array $payload = ['action' => 'apply', 'changeSummary' => ['add' => 1, 'change' => 0, 'remove' => 0, 'operation' => 'apply'], 'outputs' => ['service_url' => ['value' => 'https://pilot.example.run.app']]]): Result
{
    return new Result(Outcome::Success, rawOutput: json_encode($payload), sandboxed: false);
}

/** What a real isolation backend (bwrap, or a Cloud Run Jobs Runner) hands back. */
function sandboxedSuccess(array $payload = ['action' => 'apply', 'changeSummary' => ['add' => 1, 'change' => 0, 'remove' => 0, 'operation' => 'apply'], 'outputs' => ['service_url' => ['value' => 'https://pilot.example.run.app']]]): Result
{
    return new Result(Outcome::Success, rawOutput: json_encode($payload), sandboxed: true);
}

beforeEach(function () {
    Http::fake(['sts.googleapis.com/*' => Http::response(['access_token' => 'downscoped-token'])]);
});

it('does not let an unsandboxed apply report a plain success', function () {
    $enforced = TofuApplyResult::fromPopcornResult(sandboxedSuccess());
    $advisory = TofuApplyResult::fromPopcornResult(unsandboxedSuccess());

    // Both ran, so both are `successful` — the run is not the lie. What must differ is the posture.
    expect($enforced->successful)->toBeTrue();
    expect($advisory->successful)->toBeTrue();

    expect($enforced->sandboxed)->toBeTrue();
    expect($enforced->isAdvisory())->toBeFalse();

    expect($advisory->sandboxed)->toBeFalse();
    expect($advisory->isAdvisory())->toBeTrue();
});

it('carries the posture through a failed run too — a failure under no isolation is still unenforced', function () {
    $result = TofuApplyResult::fromPopcornResult(new Result(Outcome::NonZeroExit, error: 'tofu apply failed', sandboxed: false));

    expect($result->successful)->toBeFalse();
    expect($result->sandboxed)->toBeFalse();
    expect($result->isAdvisory())->toBeTrue();
});

it('the dispatcher reports the posture its Runner actually delivered, not the one its Grant asked for', function () {
    $dispatcher = new TofuApplyDispatcher(new RecordingRunner(unsandboxedSuccess()), new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules');

    $result = $dispatcher->apply('broad-source-token', new TofuApplyRequest('tenant-a', '/fake/tenants/tenant-a'));

    // The Grant asks for `Net::Scoped` and a two-path filesystem view either way; the Runner is
    // what decides whether that is a policy or a wish.
    expect($result->isAdvisory())->toBeTrue();
});

it('surfaces the posture on ProvisionResult so a programmatic caller cannot miss it', function () {
    $rootConfigsRoot = sys_get_temp_dir().'/beam-tofu-posture-test-'.uniqid();

    $destination = new CloudRunDestination(
        project: 'splicewire',
        region: 'us-central1',
        network: 'projects/splicewire/global/networks/beam-tenant-network',
        subnetwork: 'beam-tenant-subnet-us-central1',
        tofu: new TofuApplyDispatcher(new RecordingRunner(unsandboxedSuccess()), new CabTokenMinter, 'splicewire-beam-tofu-state', '/fake/tofu/modules'),
        renderer: new Splicewire\Beam\Provision\Tofu\TenantRootConfigRenderer('/fake/tofu/modules'),
        rootConfigsRoot: $rootConfigsRoot,
    );

    $result = $destination->provision('token', new RepoRef(owner: 'splicewire', name: 'pilot', branch: 'main', image: 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123'), 'pilot.example.com');

    expect($result->status)->toBe('provisioned');
    expect($result->sandboxed)->toBeFalse();
});

it('leaves ProvisionResult->sandboxed null where isolation is not a concept, rather than defaulting it true', function () {
    // `LaravelCloudDestination` drives a vendor CLI, not a Popcorn Runner — "sandboxed: true" would
    // be a claim nobody made. Null is the honest third state (same idiom as `AGENTS.md`'s
    // three-state publish gate: an omission and a decision must not be spelled identically).
    $result = new Splicewire\Beam\Provision\ProvisionResult(appId: 'x', url: null, status: 'provisioned');

    expect($result->sandboxed)->toBeNull();
});

it('lets a host swap the apply Runner by binding the popcorn contract, without touching this package', function () {
    $runner = new RecordingRunner(sandboxedSuccess());
    app()->instance(Runner::class, $runner);

    $destination = app(CloudRunDestination::class);

    $result = $destination->provision('token', new RepoRef(owner: 'splicewire', name: 'pilot', branch: 'main', image: 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123'), 'pilot.example.com');

    expect($runner->manifest?->name)->toBe('gcp-tofu-apply');
    expect($result->sandboxed)->toBeTrue();
});

it('falls back to NullRunner — and says so — when no host binding exists', function () {
    expect(app()->bound(Runner::class))->toBeFalse();

    $destination = app(CloudRunDestination::class);

    expect($destination)->toBeInstanceOf(CloudRunDestination::class);
});
