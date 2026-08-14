<?php

namespace Splicewire\Beam\Provision\Tofu;

/**
 * Everything {@see TenantRootConfigRenderer} needs to render one tenant's `main.tf.json` against
 * ticket 07's `cloud-run-service` + `tenant-database` modules. `tenantId` is the single source
 * both the GCS state prefix (ticket 06) and the Cloud Run service name / runtime service account
 * id are derived from — one id, not three independently-tracked ones.
 */
final readonly class TenantDeployConfig
{
    /**
     * @param  array<string, string>  $envVars
     */
    public function __construct(
        public string $tenantId,
        public string $projectId,
        public string $region,
        public string $network,
        public string $subnetwork,
        public string $image,
        public array $envVars = [],
        // Default matches cloud-run-service's own default (public beam-tier site). Found live,
        // ticket 10: an org policy (`iam.allowedPolicyMemberDomains`, blocking `allUsers`) can make
        // this unsatisfiable for a given GCP org — a real per-org constraint, not a substrate bug.
        public bool $allowUnauthenticated = true,
    ) {}
}
