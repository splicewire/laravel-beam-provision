<?php

declare(strict_types=1);

return [
    /*
     | GCP project/network target the CloudRunDestination binding provisions into — one target per
     | app (splicewire's own project today; a consumer overrides these via env if it ever needs a
     | second target). Values default to what gcp-cloud-run-provisioning ticket 10's live pilot
     | proved against.
     */
    'project' => env('GCP_PROJECT', 'splicewire'),
    'region' => env('GCP_REGION', 'us-central1'),
    'network' => env('GCP_NETWORK', 'projects/splicewire/global/networks/beam-tenant-network'),
    'subnetwork' => env('GCP_SUBNETWORK', 'beam-tenant-subnet-us-central1'),

    /*
     | The GCS bucket backing per-tenant OpenTofu state (ticket 06) — never Tofu-managed itself,
     | bootstrapped once, chicken-and-egg with its own backend block.
     */
    'state_bucket' => env('BEAM_TOFU_STATE_BUCKET', 'splicewire-beam-tofu-state'),

    /*
     | Where TenantRootConfigRenderer writes each tenant's rendered main.tf.json before apply.
     | Ephemeral by design (ticket 09: "rendered fresh per invocation, nothing to reconcile") — a
     | scratch dir is correct both locally and in a CI runner's throwaway filesystem.
     */
    'root_configs_root' => env('BEAM_TOFU_ROOT_CONFIGS_ROOT', sys_get_temp_dir().'/beam-tofu-root-configs'),

    /*
     | Cloud Run's `allUsers` invoker binding. Target-wide (an org policy property, not a
     | per-tenant one — ticket 10's own finding), so it lives here rather than per-provision-call.
     */
    'allow_unauthenticated' => (bool) env('GCP_ALLOW_UNAUTHENTICATED', true),

    /*
     | tofu apply's wall-clock ceiling — NullRunner's own 60s default is sized for quick
     | transforms, not a real `tofu apply` against live cloud APIs (ticket 10: Cloud SQL instance
     | creation alone can take several minutes).
     */
    'apply_wall_ms' => (int) env('BEAM_TOFU_APPLY_WALL_MS', 900_000),

    /*
     | Optional shared tofu provider plugin cache dir — speeds up repeated applies (e.g. a CI
     | runner reusing a cache across jobs). Null = no cache dir granted to the Runner.
     */
    'plugin_cache_dir' => env('BEAM_TOFU_PLUGIN_CACHE_DIR'),
];
