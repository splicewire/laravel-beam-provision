<?php

use Illuminate\Support\Facades\Http;
use Splicewire\Beam\Provision\Tofu\CabTokenMinter;

it('exchanges a broad token for a tenant-prefix-scoped CAB token via STS', function () {
    Http::fake([
        'sts.googleapis.com/*' => Http::response([
            'access_token' => 'downscoped-token-abc',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $minter = new CabTokenMinter;

    $token = $minter->downscope('broad-token-xyz', 'splicewire-beam-tofu-state', 'tenants/tenant-a/');

    expect($token)->toBe('downscoped-token-abc');

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://sts.googleapis.com/v1/token');
        expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:token-exchange');
        expect($request['subject_token'])->toBe('broad-token-xyz');
        expect($request['subject_token_type'])->toBe('urn:ietf:params:oauth:token-type:access_token');
        expect($request['requested_token_type'])->toBe('urn:ietf:params:oauth:token-type:access_token');

        $options = json_decode($request['options'], true);
        $rule = $options['accessBoundary']['accessBoundaryRules'][0];

        expect($rule['availableResource'])->toBe('//storage.googleapis.com/projects/_/buckets/splicewire-beam-tofu-state');
        expect($rule['availabilityCondition']['expression'])->toBe(
            "resource.name.startsWith('projects/_/buckets/splicewire-beam-tofu-state/objects/tenants/tenant-a/') || "
            ."api.getAttribute('storage.googleapis.com/objectListPrefix', '').startsWith('tenants/tenant-a/')"
        );

        return true;
    });
});

it('throws when the STS exchange fails', function () {
    Http::fake([
        'sts.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $minter = new CabTokenMinter;

    expect(fn () => $minter->downscope('broad-token-xyz', 'splicewire-beam-tofu-state', 'tenants/tenant-a/'))
        ->toThrow(RuntimeException::class);
});
