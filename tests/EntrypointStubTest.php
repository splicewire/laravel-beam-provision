<?php

use Illuminate\Support\Facades\Artisan;
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

it('boots and works out of the box — standalone is the default role', function () {
    // A freshly cut host has no deploy pipeline to run the migrate role, and a default that fails on
    // first deploy is not a default.
    expect(entrypointStubCode())->toContain('${APP_ROLE:-standalone}');
});

it('takes the role as an argument first and an env var second', function () {
    // The positional form is primary — it is container-native (`docker run <image> migrate`), it shows
    // up in a deploy config as `args` rather than as ambient state, and nothing can leave it set by
    // accident. APP_ROLE exists only because this package's own Cloud Run module declares no
    // command/args, so today the deploy path can reach an env var and cannot reach an argument.
    expect(entrypointStubCode())->toContain('ROLE="${1:-${APP_ROLE:-standalone}}"');
});

it('expresses the three postures on ONE axis, with no boolean second axis', function () {
    $code = entrypointStubCode();

    // An earlier draft had APP_ROLE plus a MIGRATE_ON_BOOT boolean: two axes for one decision, which
    // made `APP_ROLE=web MIGRATE_ON_BOOT=true` a legal spelling of standalone. Four combinations
    // describing three postures is a contradiction the reader has to resolve; one axis cannot express
    // it. This asserts the second axis has not crept back.
    expect($code)->not->toContain('MIGRATE_ON_BOOT')
        ->and($code)->toContain('standalone)')
        ->and($code)->toContain('web)')
        ->and($code)->toContain('migrate)');
});

it('refuses to serve a stale schema in the web role', function () {
    // The half that makes a non-migrating role safe. Skipping migrations without this would let a
    // deploy that forgot the migrate role serve a stale schema silently.
    $code = entrypointStubCode();

    expect($code)->toContain('migrate:status --pending')
        ->and($code)->toContain('exit 1');
});

/** The web guard's migrate:status arguments, exactly as the stub's code passes them. */
function webGuardStatusArguments(): string
{
    preg_match('/web\)\s.*?php artisan migrate:status ([^>]*?)\s*>/s', entrypointStubCode(), $m);
    expect($m[1] ?? null)->not->toBeNull('the web role runs no migrate:status guard');

    return $m[1];
}

it('the web guard EXITS NON-ZERO while a migration is pending and 0 when none is (launch ticket 45)', function () {
    // The guard's own arguments, parsed as a command line (as the shell hands them over: a bare `--pending` is null),
    // through the framework's real migrate:status. A bare `--pending` exits 0 with migrations pending: the guard guards
    // nothing (VALUE_OPTIONAL, default false; StatusCommand :87-88, :137).
    $dir = sys_get_temp_dir().'/beam-provision-t45-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/2026_11_12_000000_t45_probe.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::create('t45_probe', fn (\$t) => \$t->id()); } };\n");
    app('migrator')->path($dir);
    $guard = 'migrate:status '.webGuardStatusArguments();

    expect(Artisan::call('migrate:install'))->toBe(0);
    expect(Artisan::call($guard))->not->toBe(0, "`{$guard}` passed with a migration pending: the web role would serve a stale schema");

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
    expect(Artisan::call($guard))->toBe(0, "`{$guard}` refused with nothing pending");

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('rejects an unknown role rather than falling through to a default', function () {
    // An omission and a decision must not be spelled identically — and here a wrong guess is either a
    // container that will not migrate or one that migrates when it should not.
    expect(entrypointStub())->toContain('unknown role');
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
