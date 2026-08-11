<?php

namespace Splicewire\Beam\Provision;

/**
 * A provider-agnostic repo reference, shared by both {@see Destination} adapters rather than
 * forking the interface per provider.
 *
 * **The one flagged wrinkle** (gcp-cloud-run-provisioning map, ticket 01's load-bearing
 * finding): `$connection` is provider-specific by nature, not by accident. {@see
 * LaravelCloudDestination} ignores it entirely — Laravel Cloud's git integration is
 * account-level, with no separate "connection resource" concept. {@see CloudRunDestination}
 * *requires* it — it must be the resource name of an already-created Cloud Build connection
 * (e.g. `projects/splicewire/locations/us-central1/connections/splicewire-org-github`). Cloud
 * Build's git integration has no bare-repo-URL path; a connection is a one-time, per-GitHub-
 * account-or-org prerequisite this package deliberately doesn't automate (that one-time GitHub
 * App install is the gcp-cloud-run-provisioning map's ticket 02's job, already done). The
 * `Destination` interface stayed provider-agnostic across both adapters with zero signature
 * changes — this field is where the real asymmetry between the two providers lives instead,
 * called out here rather than papered over.
 */
final readonly class RepoRef
{
    public function __construct(
        public string $owner,
        public string $name,
        public string $branch = 'main',
        public ?string $connection = null,
    ) {}
}
