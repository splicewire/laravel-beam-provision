<?php

namespace Splicewire\Beam\Provision\Tofu;

use RuntimeException;
use stdClass;

/**
 * Renders the per-tenant root config `tofu/README.md` sketches: a `main.tf.json` wiring ticket
 * 07's `cloud-run-service` + `tenant-database` modules together, plus the one resource neither
 * module creates itself — a per-tenant Cloud Run runtime service account. `tenant-database`
 * deliberately takes `secret_accessor_member` as an *input* rather than minting an identity itself
 * ("the Cloud Run service's own runtime SA, not a shared identity") — this renderer is where that
 * identity actually gets created and threaded to both modules, so the *shared* default compute SA
 * never becomes the accessor for a tenant's database credential.
 *
 * Rendered fresh per invocation, no diffing against a previous render — matches ticket 09's own
 * `apply.php` framing: ephemeral filesystem (ticket 01), nothing to reconcile.
 */
final class TenantRootConfigRenderer
{
    public function __construct(
        private readonly string $modulesDir,
        private readonly string $providerVersionConstraint = '~> 6.0',
    ) {}

    public function render(TenantDeployConfig $config, string $rootConfigDir): void
    {
        if (! is_dir($rootConfigDir) && ! mkdir($rootConfigDir, recursive: true) && ! is_dir($rootConfigDir)) {
            throw new RuntimeException("TenantRootConfigRenderer: could not create rootConfigDir `{$rootConfigDir}`.");
        }

        $document = [
            'terraform' => [
                // bucket/prefix supplied via -backend-config at `tofu init` time (ticket 06/09),
                // never literals here.
                'backend' => ['gcs' => new stdClass],
                'required_providers' => [
                    'google' => [
                        'source' => 'hashicorp/google',
                        'version' => $this->providerVersionConstraint,
                    ],
                ],
            ],
            // `provider` is the one top-level block JSON syntax always array-wraps, even for a
            // single instance (aliasing support) — resource/module blocks below are plain objects.
            'provider' => [
                'google' => [
                    [
                        'project' => $config->projectId,
                        'region' => $config->region,
                    ],
                ],
            ],
            'resource' => [
                'google_service_account' => [
                    'runtime' => [
                        'project' => $config->projectId,
                        'account_id' => $this->runtimeServiceAccountId($config->tenantId),
                        'display_name' => "beam runtime — {$config->tenantId}",
                    ],
                ],
                // Found live (ticket 10): the built-in Cloud SQL Auth Proxy socket (ticket 01's
                // finding) authenticates AS the Cloud Run service's own runtime identity via the
                // Cloud SQL Admin API — without this, the socket connection is refused, not merely
                // denied. tenant-database's `secret_accessor_member` grant doesn't cover this; it's
                // a separate capability the runtime SA needs regardless of which secret it can read.
                'google_project_iam_member' => [
                    'runtime_cloudsql_client' => [
                        'project' => $config->projectId,
                        'role' => 'roles/cloudsql.client',
                        'member' => 'serviceAccount:${google_service_account.runtime.email}',
                    ],
                ],
            ],
            'module' => [
                'tenant_database' => [
                    'source' => "{$this->modulesDir}/tenant-database",
                    'project_id' => $config->projectId,
                    'region' => $config->region,
                    'tenant_id' => $config->tenantId,
                    'network' => $config->network,
                    'secret_accessor_member' => 'serviceAccount:${google_service_account.runtime.email}',
                ],
                'cloud_run_service' => [
                    'source' => "{$this->modulesDir}/cloud-run-service",
                    'project_id' => $config->projectId,
                    'region' => $config->region,
                    'service_name' => $config->tenantId,
                    'image' => $config->image,
                    // Direct VPC egress (found live, ticket 10) — the built-in Cloud SQL Auth
                    // Proxy needs a real network path to the tenant-database's private IP.
                    // cloud-run-service's `network` var wants a plain *name*, unlike
                    // tenant-database's self-link — derived from the same VPC here.
                    'network' => $this->shortResourceName($config->network),
                    'subnetwork' => $config->subnetwork,
                    'env_vars' => $config->envVars === [] ? new stdClass : $config->envVars,
                    'secret_env_vars' => [
                        'DB_CREDENTIALS_JSON' => [
                            'secret_id' => '${module.tenant_database.secret_id}',
                            'version' => 'latest',
                        ],
                    ],
                    'cloudsql_connection_names' => ['${module.tenant_database.connection_name}'],
                    'service_account_email' => '${google_service_account.runtime.email}',
                    'allow_unauthenticated' => $config->allowUnauthenticated,
                ],
            ],
            'output' => [
                'service_url' => ['value' => '${module.cloud_run_service.service_url}'],
                'service_name' => ['value' => '${module.cloud_run_service.service_name}'],
                'db_connection_name' => ['value' => '${module.tenant_database.connection_name}'],
            ],
        ];

        file_put_contents(
            "{$rootConfigDir}/main.tf.json",
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function shortResourceName(string $selfLinkOrName): string
    {
        $segments = explode('/', rtrim($selfLinkOrName, '/'));

        return (string) end($segments);
    }

    /** GCP account_id: 6-30 chars, lowercase letters/digits/hyphens, must start with a letter. */
    private function runtimeServiceAccountId(string $tenantId): string
    {
        $slug = ltrim(preg_replace('/[^a-z0-9-]/', '-', strtolower($tenantId)), '-');

        if ($slug === '' || ! ctype_alpha($slug[0])) {
            $slug = "t-{$slug}";
        }

        return rtrim(substr($slug, 0, 26), '-').'-rt';
    }
}
