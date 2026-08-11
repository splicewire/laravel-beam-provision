> You are in **splicewire/laravel-beam-provision** — the provisioning-destination seam for standing up a repo on a cloud provider.

A Laravel package that owns the `Destination` interface (`provision` / `teardown` / `healthCheck`)
and its two concrete adapters, `LaravelCloudDestination` and `CloudRunDestination` — proving that
one contract generalizes cleanly across two independent cloud providers. It ships the shared
`RepoRef`/`ProvisionResult`/`HealthResult` value objects and the lifecycle events
(`ProvisioningStarted`, `Provisioned`, `ProvisionFailed`, `TearingDown`, `TornDown`) both adapters
dispatch, so other packages can subscribe with zero coupling back into this one.

It does **not** own orchestration: no repo-forking, no env-wiring policy, no Artisan-command
trigger surface, and no tower integration live here. Those are downstream consumers of this
contract, built against `Destination` rather than folded into it — deliberately out of scope for
this package.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
