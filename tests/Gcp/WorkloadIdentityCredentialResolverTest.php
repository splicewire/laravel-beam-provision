<?php

use Illuminate\Support\Facades\Http;
use Splicewire\Beam\Provision\Gcp\WorkloadIdentityCredentialResolver;

it('exchanges an OIDC subject token for a federated token, then impersonates the target SA', function () {
    Http::fake([
        'sts.googleapis.com/*' => Http::response([
            'access_token' => 'federated-token-abc',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]),
        'iamcredentials.googleapis.com/*' => Http::response([
            'accessToken' => 'impersonated-token-xyz',
            'expireTime' => '2026-01-01T00:00:00Z',
        ]),
    ]);

    $resolver = new WorkloadIdentityCredentialResolver;

    $token = $resolver->resolve(
        subjectToken: 'tower-self-signed-jwt',
        workloadAudience: '//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/tower-pool/providers/tower-oidc',
        serviceAccountEmail: 'beam-provision@splicewire.iam.gserviceaccount.com',
    );

    expect($token)->toBe('impersonated-token-xyz');

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://sts.googleapis.com/v1/token') {
            return true;
        }

        expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:token-exchange');
        expect($request['audience'])->toBe('//iam.googleapis.com/projects/123/locations/global/workloadIdentityPools/tower-pool/providers/tower-oidc');
        expect($request['subject_token'])->toBe('tower-self-signed-jwt');
        expect($request['subject_token_type'])->toBe('urn:ietf:params:oauth:token-type:jwt');
        expect($request['requested_token_type'])->toBe('urn:ietf:params:oauth:token-type:access_token');

        return true;
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'iamcredentials.googleapis.com')) {
            return true;
        }

        expect($request->url())->toBe(
            'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/beam-provision@splicewire.iam.gserviceaccount.com:generateAccessToken'
        );
        expect($request->hasHeader('Authorization', 'Bearer federated-token-abc'))->toBeTrue();
        expect($request['scope'])->toBe(['https://www.googleapis.com/auth/cloud-platform']);

        return true;
    });
});

it('throws when the STS exchange fails, and never calls IAM Credentials', function () {
    Http::fake([
        'sts.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $resolver = new WorkloadIdentityCredentialResolver;

    expect(fn () => $resolver->resolve('bad-jwt', '//iam.googleapis.com/...', 'sa@splicewire.iam.gserviceaccount.com'))
        ->toThrow(RuntimeException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'iamcredentials.googleapis.com'));
});

it('throws when impersonation fails after a successful exchange', function () {
    Http::fake([
        'sts.googleapis.com/*' => Http::response(['access_token' => 'federated-token-abc']),
        'iamcredentials.googleapis.com/*' => Http::response(['error' => 'permission_denied'], 403),
    ]);

    $resolver = new WorkloadIdentityCredentialResolver;

    expect(fn () => $resolver->resolve('tower-jwt', '//iam.googleapis.com/...', 'sa@splicewire.iam.gserviceaccount.com'))
        ->toThrow(RuntimeException::class);
});
