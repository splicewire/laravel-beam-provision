<?php

namespace Splicewire\Beam\Provision\Tofu;

/**
 * Everything {@see TenantDatabaseRootConfigRenderer} needs to render one tenant's
 * `main.tf.json` against ticket 15's `tenant-database` module ALONE — no `cloud-run-service`,
 * no runtime service account resource. For a consumer with no app to deploy
 * (tenant-database-upsell ticket 16's `GcpCloudSqlDestination`), {@see TenantRootConfigRenderer}'s
 * always-coupled Cloud Run + Cloud-SQL-Auth-Proxy IAM binding is unwanted, not just unused.
 */
final readonly class TenantDatabaseDeployConfig
{
    /**
     * @param  list<array{name: string, cidr: string}>  $authorizedNetworks
     */
    public function __construct(
        public string $tenantId,
        public string $projectId,
        public string $region,
        public string $secretAccessorMember,
        public bool $ipv4Enabled = true,
        public array $authorizedNetworks = [],
        public ?string $network = null,
        public string $tier = 'db-f1-micro',
    ) {}
}
