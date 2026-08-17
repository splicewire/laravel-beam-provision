<?php

use Splicewire\Beam\Provision\Tofu\TenantDatabaseDeployConfig;
use Splicewire\Beam\Provision\Tofu\TenantDatabaseRootConfigRenderer;

function makeTenantDatabaseDeployConfig(array $overrides = []): TenantDatabaseDeployConfig
{
    return new TenantDatabaseDeployConfig(
        tenantId: $overrides['tenantId'] ?? 'pilot-example-com',
        projectId: $overrides['projectId'] ?? 'splicewire',
        region: $overrides['region'] ?? 'us-central1',
        secretAccessorMember: $overrides['secretAccessorMember'] ?? 'serviceAccount:beam-provision@splicewire.iam.gserviceaccount.com',
        ipv4Enabled: $overrides['ipv4Enabled'] ?? true,
        authorizedNetworks: $overrides['authorizedNetworks'] ?? [],
        network: $overrides['network'] ?? null,
        tier: $overrides['tier'] ?? 'db-f1-micro',
    );
}

it('renders a main.tf.json wiring only the tenant-database module — no cloud-run-service, no runtime SA', function () {
    $dir = sys_get_temp_dir().'/beam-tofu-db-render-test-'.uniqid();
    $renderer = new TenantDatabaseRootConfigRenderer('/fake/tofu/modules');

    $renderer->render(makeTenantDatabaseDeployConfig(), $dir);

    expect("{$dir}/main.tf.json")->toBeFile();
    $document = json_decode(file_get_contents("{$dir}/main.tf.json"), true);

    expect($document['terraform']['backend'])->toBe(['gcs' => []]);
    expect($document['provider']['google'])->toBe([['project' => 'splicewire', 'region' => 'us-central1']]);

    expect($document)->not->toHaveKey('resource');
    expect($document['module'])->not->toHaveKey('cloud_run_service');

    expect($document['module']['tenant_database'])->toMatchArray([
        'source' => '/fake/tofu/modules/tenant-database',
        'project_id' => 'splicewire',
        'region' => 'us-central1',
        'tenant_id' => 'pilot-example-com',
        'ipv4_enabled' => true,
        'authorized_networks' => [],
        'tier' => 'db-f1-micro',
        'secret_accessor_member' => 'serviceAccount:beam-provision@splicewire.iam.gserviceaccount.com',
    ]);

    // No VPC network for a public-IP consumer — the key is omitted entirely, not set to null,
    // so the module falls through to its own `network = null` default rather than the JSON
    // encoding an explicit null that HCL would still have to interpret.
    expect($document['module']['tenant_database'])->not->toHaveKey('network');

    expect($document['output']['connection_name'])->toBe(['value' => '${module.tenant_database.connection_name}']);
    expect($document['output']['public_ip_address'])->toBe(['value' => '${module.tenant_database.public_ip_address}']);
});

it('includes authorized_networks entries and the network key when supplied', function () {
    $dir = sys_get_temp_dir().'/beam-tofu-db-render-test-'.uniqid();
    $renderer = new TenantDatabaseRootConfigRenderer('/fake/tofu/modules');

    $renderer->render(makeTenantDatabaseDeployConfig([
        'authorizedNetworks' => [['name' => 'plesk2', 'cidr' => '203.0.113.5/32']],
        'network' => 'projects/splicewire/global/networks/beam-tenant-network',
    ]), $dir);

    $document = json_decode(file_get_contents("{$dir}/main.tf.json"), true);

    expect($document['module']['tenant_database']['authorized_networks'])->toBe([
        ['name' => 'plesk2', 'cidr' => '203.0.113.5/32'],
    ]);
    expect($document['module']['tenant_database']['network'])->toBe('projects/splicewire/global/networks/beam-tenant-network');
});
