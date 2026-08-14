<?php

/**
 * The Popcorn Manifest entrypoint for the `gcp-tofu-apply` capability (gcp-cloud-run-provisioning
 * map, ticket 09). Deliberately dependency-free — no Composer autoload — so it stays runnable
 * unchanged once graduated from NullRunner to a real sandboxed Runner (bwrap binds a `/pkg`
 * directory and execs an interpreter + entrypoint; a package-autoload path is not guaranteed to
 * exist inside that jail).
 *
 * Speaks the same Stdio envelope every popcorn substrate does: stdin is
 * `{"input": {...}, "grant": {...}}`, stdout is a single JSON object on success (the value
 * channel), stderr is bounded diagnostics. On failure this script exits non-zero with nothing on
 * stdout — NullRunner (and any future Runner) maps that straight onto Popcorn's own
 * `Outcome::NonZeroExit`, so there is no second, redundant "did it work" field to invent.
 *
 * `input` shape:
 *   - action: "apply" | "destroy"
 *   - rootConfigDir: absolute path to the tenant's rendered root config (the `.tf.json` + any
 *     supporting files ticket 08/10 render — this script does not render anything)
 *   - backendBucket, tenantId: backend-config values for `tofu init` (ticket 06's convention:
 *     `-backend-config="prefix=tenants/<id>"`, no `/terraform.tfstate` suffix — the gcs backend
 *     appends `/default.tfstate` itself)
 *   - providerAccessToken: the broad destination credential — what `provider "google"` uses for
 *     the actual Cloud Run/Cloud SQL calls, via `GOOGLE_OAUTH_ACCESS_TOKEN`. Not CAB-downscoped:
 *     CAB only narrows Cloud Storage access, so resource-level isolation is not a token-level
 *     boundary the way state isolation is (see stateAccessToken).
 *   - stateAccessToken: a short-lived, CAB-downscoped GCP access token (minted host-side by
 *     `CabTokenMinter`, scoped to this tenant's own `tenants/<id>/` state prefix) — passed only to
 *     the gcs *backend* via `-backend-config=access_token=...`, never to the provider. This is the
 *     actual cryptographic isolation mechanism ticket 06 established; never written to disk/logged.
 *   - pluginCacheDir: optional, read-only shared provider cache across tenants
 *   - tofuBinary: optional override, defaults to `tofu` (resolved via PATH)
 */
$raw = stream_get_contents(STDIN);
$envelope = json_decode($raw, true);

if (! is_array($envelope) || ! isset($envelope['input']) || ! is_array($envelope['input'])) {
    fwrite(STDERR, "apply.php: stdin was not a valid popcorn envelope ({\"input\": {...}}).\n");
    exit(1);
}

$input = $envelope['input'];

$required = ['action', 'rootConfigDir', 'backendBucket', 'tenantId'];
foreach ($required as $field) {
    if (! isset($input[$field]) || $input[$field] === '') {
        fwrite(STDERR, "apply.php: missing required input field `{$field}`.\n");
        exit(1);
    }
}

$action = $input['action'];
if (! in_array($action, ['apply', 'destroy'], strict: true)) {
    fwrite(STDERR, "apply.php: unknown action `{$action}` (expected apply|destroy).\n");
    exit(1);
}

$rootConfigDir = rtrim((string) $input['rootConfigDir'], '/');
if (! is_dir($rootConfigDir)) {
    fwrite(STDERR, "apply.php: rootConfigDir `{$rootConfigDir}` does not exist.\n");
    exit(1);
}

$tofuBinary = (string) ($input['tofuBinary'] ?? 'tofu');

// The explicit allowlist the `tofu` child actually sees — independent of whatever this script's
// own process inherited. Under NullRunner this is the only real enforcement happening yet (the
// Grant itself is advisory until the bwrap graduation); it is written this way so that graduation
// is a Runner swap, not a rewrite of this allowlist.
$childEnv = [
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
    'HOME' => (string) ($input['home'] ?? getenv('HOME') ?: sys_get_temp_dir()),
    'TF_IN_AUTOMATION' => '1',
];

// Two distinct tokens, two distinct credential surfaces — found live (a single CAB-downscoped
// token 403'd on `run.services.create`; CAB only narrows Cloud Storage access, so a token scoped
// to the tenant's state prefix has *no* Cloud Run/Cloud SQL permissions at all):
//   - `providerAccessToken` (broad, the caller's own destination credential): what
//     `provider "google"` uses for actual resource calls, via GOOGLE_OAUTH_ACCESS_TOKEN. Cloud
//     Run/Cloud SQL resources aren't CAB-scopable the way GCS objects are, so resource-level
//     isolation is NOT a cryptographic boundary today — it rides on each tenant's apply only ever
//     touching its own rendered root config (Grant-scoped filesystem), not on IAM.
//   - `stateAccessToken` (CAB-downscoped to this tenant's `tenants/<id>/` prefix): passed only to
//     the gcs *backend* via `-backend-config=access_token=...`, which the backend documents as
//     an alternative to `credentials` — this is the actual, cryptographic isolation boundary
//     (ticket 06's "per-tenant state is the actual isolation mechanism").
if (isset($input['providerAccessToken']) && $input['providerAccessToken'] !== '') {
    $childEnv['GOOGLE_OAUTH_ACCESS_TOKEN'] = (string) $input['providerAccessToken'];
}

if (isset($input['pluginCacheDir']) && $input['pluginCacheDir'] !== '') {
    $childEnv['TF_PLUGIN_CACHE_DIR'] = (string) $input['pluginCacheDir'];
}

/**
 * @param  list<string>  $argv
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function run_tofu(array $argv, array $env): array
{
    $process = proc_open($argv, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $env);

    if (! is_resource($process)) {
        fwrite(STDERR, 'apply.php: failed to spawn `'.$argv[0]."`.\n");
        exit(1);
    }

    // Closed immediately, not inherited: `-input=false` already refuses interactive prompts, but
    // an EOF'd stdin makes a stray prompt fail fast instead of hanging the job.
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** Parse tofu's `-json` newline-delimited log into the two events the caller actually needs. */
function summarize(string $ndjson): array
{
    $changeSummary = null;
    $outputs = null;

    foreach (ndjson_events($ndjson) as $event) {
        if ($event['type'] === 'change_summary') {
            $changeSummary = $event['changes'] ?? null;
        }

        if ($event['type'] === 'outputs') {
            $outputs = $event['outputs'] ?? [];
        }
    }

    return [$changeSummary, $outputs];
}

/** @return list<array<string, mixed>> */
function ndjson_events(string $ndjson): array
{
    $events = [];

    foreach (explode("\n", trim($ndjson)) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $event = json_decode($line, true);
        if (is_array($event) && isset($event['type'])) {
            $events[] = $event;
        }
    }

    return $events;
}

/**
 * `tofu ... -json`'s error/warning text lives on **stdout** as `type: "diagnostic"` events, not
 * on stderr (found live — a failed apply produced empty stderr, exit 1, and no readable reason
 * until this was added). Renders the same summary/detail a human would see without `-json`.
 */
function diagnostics(string $ndjson): string
{
    $lines = [];

    foreach (ndjson_events($ndjson) as $event) {
        if ($event['type'] !== 'diagnostic') {
            continue;
        }

        $diag = $event['diagnostic'] ?? [];
        $lines[] = trim(($diag['severity'] ?? 'error').': '.($diag['summary'] ?? '').
            (isset($diag['detail']) ? "\n".$diag['detail'] : ''));
    }

    return implode("\n\n", $lines);
}

$init = run_tofu([
    $tofuBinary,
    "-chdir={$rootConfigDir}",
    'init',
    '-input=false',
    // Root config dirs are rendered fresh per invocation in production (ticket 01's ephemeral
    // Cloud Run Jobs filesystem finding), so there is normally nothing to reconcile — `-reconfigure`
    // just makes that the always-safe case too, rather than trusting a stale local `.terraform`
    // cache (found live, debugging this script: reusing a scratch dir across two backend-config
    // shapes fails init with "Backend configuration changed" otherwise).
    '-reconfigure',
    '-backend-config=bucket='.$input['backendBucket'],
    '-backend-config=prefix=tenants/'.$input['tenantId'],
    ...(isset($input['stateAccessToken']) && $input['stateAccessToken'] !== ''
        ? ['-backend-config=access_token='.$input['stateAccessToken']]
        : []),
], $childEnv);

if ($init['exitCode'] !== 0) {
    fwrite(STDERR, "apply.php: tofu init failed (exit {$init['exitCode']}):\n".$init['stderr']);
    exit($init['exitCode']);
}

$run = run_tofu([
    $tofuBinary,
    "-chdir={$rootConfigDir}",
    $action,
    '-auto-approve',
    '-input=false',
    '-json',
], $childEnv);

if ($run['exitCode'] !== 0) {
    $reason = diagnostics($run['stdout']) ?: $run['stderr'];
    fwrite(STDERR, "apply.php: tofu {$action} failed (exit {$run['exitCode']}):\n{$reason}");
    exit($run['exitCode']);
}

[$changeSummary, $outputs] = summarize($run['stdout']);

echo json_encode([
    'action' => $action,
    'changeSummary' => $changeSummary,
    'outputs' => $outputs,
]);

exit(0);
