<?php

use Splicewire\Beam\Provision\BeamProvisionServiceProvider;

/**
 * The published container entrypoint's contract, asserted against the stub this package ships.
 *
 * ## Why the stub is tested here and not only at hosts
 *
 * The stub exists because three copies agreed and nothing kept them agreeing: measured 2026-09-09, all
 * three starters' `docker/entrypoint.sh` were byte-identical (`md5 304eb9c9…`) and all three ran
 * `exec php artisan serve` — the single-threaded dev server — in production. Every host cut from a
 * starter inherited it, and no instrument anywhere could see the boot path.
 *
 * A host's *published copy* can drift (that is what publishing means), and that is the host's business.
 * What must not drift is the thing every future host is cut from.
 *
 * ## What these assertions can and cannot see
 *
 * They prove the TEXT of the stub. They cannot prove a container boots — that needs a container. Do not
 * read a green here as a deploy guarantee; read it as "the stub still says the things we decided".
 */
function entrypointStub(): string
{
    return (string) file_get_contents(__DIR__.'/../resources/docker/entrypoint.sh.stub');
}

/** The stub with comment lines stripped — what the shell will actually execute. */
function entrypointStubCode(): string
{
    return collect(explode("\n", entrypointStub()))
        ->reject(fn (string $line): bool => str_starts_with(ltrim($line), '#'))
        ->implode("\n");
}

it('is published under a tag a host can actually name', function () {
    $paths = BeamProvisionServiceProvider::pathsToPublish(BeamProvisionServiceProvider::class, 'beam-provision-docker');

    expect($paths)->not->toBeEmpty('Nothing is registered under the beam-provision-docker tag.')
        ->and(array_values($paths))->toContain(base_path('docker/entrypoint.sh'));
});

it('serves under a real application server, never the dev server', function () {
    $code = entrypointStubCode();

    // `artisan serve` is single-threaded: container concurrency above 1 queues head-of-line. This is
    // the defect the stub exists to stop propagating to new hosts.
    expect($code)->not->toContain('artisan serve')
        ->and($code)->toContain('octane:start')
        ->and($code)->toContain('--server=frankenphp');
});

it('execs the server so it is PID 1 and receives SIGTERM', function () {
    // Without `exec` the shell stays PID 1, swallows SIGTERM, and in-flight requests are killed at the
    // grace deadline instead of draining.
    expect(entrypointStubCode())->toContain('exec php artisan');
});

it('binds the port the platform assigns', function () {
    expect(entrypointStubCode())->toContain('${PORT:-8080}');
});

it('boots and works out of the box — migrations run by default', function () {
    // The honest default for a starter: a freshly cut host has no deploy pipeline to run a migrate
    // role, so a default of `false` would make its first deploy fail by design.
    expect(entrypointStubCode())->toContain('MIGRATE_ON_BOOT:-true}');
});

it('refuses to serve a stale schema when migrations are moved off the boot path', function () {
    // The half that makes MIGRATE_ON_BOOT=false safe. Skipping migrations without this would let a
    // deploy that forgot the migrate role serve a stale schema silently.
    $code = entrypointStubCode();

    expect($code)->toContain('migrate:status --pending')
        ->and($code)->toContain('exit 1');
});

it('rejects an unknown role rather than falling through to web', function () {
    // An omission and a decision must not be spelled identically.
    expect(entrypointStub())->toContain('unknown APP_ROLE');
});

it('guards tenants:migrate rather than assuming a tenancy layer', function () {
    $code = entrypointStubCode();

    // Two of the three starters install no tenancy. And the guard must be a real probe: `artisan help
    // <unknown>` exits 0, so it cannot distinguish a missing command from a present one — measured.
    expect($code)->toContain('tenants:migrate')
        ->and($code)->toContain('list --raw')
        ->and($code)->not->toContain('artisan help tenants:migrate');
});

it('is valid shell', function () {
    // The cheapest guard that would catch a syntax error in a file no PHP linter can see.
    exec('sh -n '.escapeshellarg(__DIR__.'/../resources/docker/entrypoint.sh.stub').' 2>&1', $out, $code);

    expect($code)->toBe(0, 'sh -n rejected the stub: '.implode("\n", $out));
});
