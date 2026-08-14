<?php

use Splicewire\Beam\Provision\Tofu\TenantDeployConfig;
use Splicewire\Beam\Provision\Tofu\TenantRootConfigRenderer;

function makeTenantDeployConfig(array $overrides = []): TenantDeployConfig
{
    return new TenantDeployConfig(
        tenantId: $overrides['tenantId'] ?? 'pilot-example-com',
        projectId: $overrides['projectId'] ?? 'splicewire',
        region: $overrides['region'] ?? 'us-central1',
        network: $overrides['network'] ?? 'projects/splicewire/global/networks/beam-tenant-network',
        subnetwork: $overrides['subnetwork'] ?? 'beam-tenant-subnet-us-central1',
        image: $overrides['image'] ?? 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123',
        envVars: $overrides['envVars'] ?? [],
    );
}

it('renders a main.tf.json wiring both modules through a per-tenant runtime service account', function () {
    $dir = sys_get_temp_dir().'/beam-tofu-render-test-'.uniqid();
    $renderer = new TenantRootConfigRenderer('/fake/tofu/modules');

    $renderer->render(makeTenantDeployConfig(), $dir);

    expect("{$dir}/main.tf.json")->toBeFile();
    $document = json_decode(file_get_contents("{$dir}/main.tf.json"), true);

    // terraform/backend: an empty gcs block — bucket/prefix supplied via -backend-config at init time.
    expect($document['terraform']['backend'])->toBe(['gcs' => []]);
    expect($document['terraform']['required_providers']['google']['source'])->toBe('hashicorp/google');

    // provider is always array-wrapped in JSON syntax, even for a single instance.
    expect($document['provider']['google'])->toBe([['project' => 'splicewire', 'region' => 'us-central1']]);

    // resource/module blocks are plain objects, not array-wrapped.
    expect($document['resource']['google_service_account']['runtime'])->toMatchArray([
        'project' => 'splicewire',
        'account_id' => 'pilot-example-com-rt',
    ]);

    expect($document['module']['tenant_database'])->toMatchArray([
        'source' => '/fake/tofu/modules/tenant-database',
        'tenant_id' => 'pilot-example-com',
        'network' => 'projects/splicewire/global/networks/beam-tenant-network',
        'secret_accessor_member' => 'serviceAccount:${google_service_account.runtime.email}',
    ]);

    expect($document['module']['cloud_run_service'])->toMatchArray([
        'source' => '/fake/tofu/modules/cloud-run-service',
        'service_name' => 'pilot-example-com',
        'image' => 'us-central1-docker.pkg.dev/splicewire/beam/pilot:abc123',
        // cloud-run-service's `network` var wants a plain name, unlike tenant-database's
        // self-link above — derived from the same VPC (found live, ticket 10: Direct VPC egress
        // is required for the built-in Cloud SQL Auth Proxy to reach a private-IP instance).
        'network' => 'beam-tenant-network',
        'subnetwork' => 'beam-tenant-subnet-us-central1',
        'service_account_email' => '${google_service_account.runtime.email}',
        'allow_unauthenticated' => true,
    ]);
    expect($document['module']['cloud_run_service']['cloudsql_connection_names'])
        ->toBe(['${module.tenant_database.connection_name}']);
    expect($document['module']['cloud_run_service']['secret_env_vars']['DB_CREDENTIALS_JSON'])->toBe([
        'secret_id' => '${module.tenant_database.secret_id}',
        'version' => 'latest',
    ]);

    expect($document['output']['service_url'])->toBe(['value' => '${module.cloud_run_service.service_url}']);
});

it('renders empty env_vars as a JSON object, not an array — the module expects a map(string)', function () {
    $dir = sys_get_temp_dir().'/beam-tofu-render-test-'.uniqid();
    $renderer = new TenantRootConfigRenderer('/fake/tofu/modules');

    $renderer->render(makeTenantDeployConfig(['envVars' => []]), $dir);

    // Decode with associative=false so an empty PHP array vs. an empty stdClass are distinguishable.
    $document = json_decode(file_get_contents("{$dir}/main.tf.json"));

    expect($document->module->cloud_run_service->env_vars)->toBeInstanceOf(stdClass::class);
});

it('passes non-empty env_vars through as a map', function () {
    $dir = sys_get_temp_dir().'/beam-tofu-render-test-'.uniqid();
    $renderer = new TenantRootConfigRenderer('/fake/tofu/modules');

    $renderer->render(makeTenantDeployConfig(['envVars' => ['APP_ENV' => 'production']]), $dir);

    $document = json_decode(file_get_contents("{$dir}/main.tf.json"), true);

    expect($document['module']['cloud_run_service']['env_vars'])->toBe(['APP_ENV' => 'production']);
});

it('derives a valid GCP service-account id even from a long or numeric-leading tenant id', function () {
    $dir = sys_get_temp_dir().'/beam-tofu-render-test-'.uniqid();
    $renderer = new TenantRootConfigRenderer('/fake/tofu/modules');

    $renderer->render(makeTenantDeployConfig(['tenantId' => '123-a-very-long-tenant-identifier-past-thirty-chars']), $dir);

    $document = json_decode(file_get_contents("{$dir}/main.tf.json"), true);
    $accountId = $document['resource']['google_service_account']['runtime']['account_id'];

    expect(strlen($accountId))->toBeLessThanOrEqual(30);
    expect($accountId)->toMatch('/^[a-z][a-z0-9-]*$/');
});
