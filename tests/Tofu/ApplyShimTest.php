<?php

/**
 * Exercises apply.php's own guard clauses directly (no tofu, no network, no NullRunner) — the
 * fast, deterministic slice of coverage. The real `tofu init`/`apply`/`destroy` path is verified
 * empirically against the live `splicewire` GCP project (ticket 09's resolution), not here: a
 * fixture root config would need a `local` backend to stay offline, which doesn't exercise the
 * same `-backend-config` flags real tenants hit — a fake pass here would be worse than no test.
 */
function run_apply_shim(string $stdin): array
{
    $shim = dirname(__DIR__, 2).'/resources/tofu-runner/apply.php';

    $process = proc_open(['php', $shim], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

it('rejects stdin that is not a popcorn envelope', function () {
    $result = run_apply_shim('not json');

    expect($result['exitCode'])->not->toBe(0);
    expect($result['stderr'])->toContain('not a valid popcorn envelope');
});

it('rejects an envelope missing required input fields', function () {
    $result = run_apply_shim(json_encode(['input' => ['action' => 'apply']]));

    expect($result['exitCode'])->not->toBe(0);
    expect($result['stderr'])->toContain('missing required input field');
});

it('rejects an unknown action', function () {
    $result = run_apply_shim(json_encode(['input' => [
        'action' => 'plan',
        'rootConfigDir' => sys_get_temp_dir(),
        'backendBucket' => 'splicewire-beam-tofu-state',
        'tenantId' => 'tenant-a',
    ]]));

    expect($result['exitCode'])->not->toBe(0);
    expect($result['stderr'])->toContain('unknown action `plan`');
});

it('rejects a rootConfigDir that does not exist', function () {
    $result = run_apply_shim(json_encode(['input' => [
        'action' => 'apply',
        'rootConfigDir' => '/does/not/exist/'.uniqid(),
        'backendBucket' => 'splicewire-beam-tofu-state',
        'tenantId' => 'tenant-a',
    ]]));

    expect($result['exitCode'])->not->toBe(0);
    expect($result['stderr'])->toContain('does not exist');
});
