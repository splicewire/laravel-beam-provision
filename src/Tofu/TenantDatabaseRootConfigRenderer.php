<?php

namespace Splicewire\Beam\Provision\Tofu;

use RuntimeException;
use stdClass;

/**
 * Renders a per-tenant root config wiring ticket 15's `tenant-database` module ALONE
 * (tenant-database-upsell ticket 16, point 1) — the lean sibling to
 * {@see TenantRootConfigRenderer}, which always couples in `cloud-run-service` + a Cloud-SQL-
 * Auth-Proxy IAM binding that a no-app-deploy consumer (a database-only destination) neither
 * needs nor should provision. Rendered fresh per invocation, no diffing against a previous
 * render — same ephemeral-filesystem framing as the sibling renderer.
 */
final class TenantDatabaseRootConfigRenderer
{
    public function __construct(
        private readonly string $modulesDir,
        private readonly string $providerVersionConstraint = '~> 6.0',
    ) {}

    public function render(TenantDatabaseDeployConfig $config, string $rootConfigDir): void
    {
        if (! is_dir($rootConfigDir) && ! mkdir($rootConfigDir, recursive: true) && ! is_dir($rootConfigDir)) {
            throw new RuntimeException("TenantDatabaseRootConfigRenderer: could not create rootConfigDir `{$rootConfigDir}`.");
        }

        $document = [
            'terraform' => [
                // bucket/prefix supplied via -backend-config at `tofu init` time, never literals here.
                'backend' => ['gcs' => new stdClass],
                'required_providers' => [
                    'google' => [
                        'source' => 'hashicorp/google',
                        'version' => $this->providerVersionConstraint,
                    ],
                ],
            ],
            'provider' => [
                'google' => [
                    [
                        'project' => $config->projectId,
                        'region' => $config->region,
                    ],
                ],
            ],
            'module' => [
                'tenant_database' => array_filter([
                    'source' => "{$this->modulesDir}/tenant-database",
                    'project_id' => $config->projectId,
                    'region' => $config->region,
                    'tenant_id' => $config->tenantId,
                    'network' => $config->network,
                    'ipv4_enabled' => $config->ipv4Enabled,
                    'authorized_networks' => $config->authorizedNetworks,
                    'tier' => $config->tier,
                    'secret_accessor_member' => $config->secretAccessorMember,
                ], static fn ($value) => $value !== null),
            ],
            'output' => [
                'connection_name' => ['value' => '${module.tenant_database.connection_name}'],
                'database_name' => ['value' => '${module.tenant_database.database_name}'],
                'secret_id' => ['value' => '${module.tenant_database.secret_id}'],
                'public_ip_address' => ['value' => '${module.tenant_database.public_ip_address}'],
            ],
        ];

        file_put_contents(
            "{$rootConfigDir}/main.tf.json",
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
