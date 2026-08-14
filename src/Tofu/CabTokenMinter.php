<?php

namespace Splicewire\Beam\Provision\Tofu;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The per-invocation half of ticket 06's isolation mechanism: exchanges the apply Runner's broad
 * access token for a short-lived **Credential Access Boundary (CAB)** token via GCP's STS
 * token-exchange endpoint, scoped down to a single tenant's `tenants/<id>/` prefix on the state
 * bucket. One SA, many downscoped tokens — the token itself, not a static IAM binding, is what
 * cryptographically prevents one tenant's apply from touching another tenant's state.
 *
 * @see https://docs.cloud.google.com/iam/docs/downscoping-short-lived-credentials
 */
final class CabTokenMinter
{
    private const STS_ENDPOINT = 'https://sts.googleapis.com/v1/token';

    /** The role the broad source token already holds on the bucket (ticket 06: `storage.objectAdmin`) — CAB only narrows, never grants beyond it. */
    private const AVAILABLE_PERMISSIONS = ['inRole:roles/storage.objectAdmin'];

    /**
     * @throws RuntimeException if the STS exchange fails
     */
    public function downscope(string $broadAccessToken, string $bucket, string $tenantPrefix): string
    {
        $accessBoundary = [
            'accessBoundary' => [
                'accessBoundaryRules' => [
                    [
                        'availableResource' => "//storage.googleapis.com/projects/_/buckets/{$bucket}",
                        'availablePermissions' => self::AVAILABLE_PERMISSIONS,
                        'availabilityCondition' => [
                            // The `objectListPrefix` clause is required alongside `resource.name.startsWith`
                            // for `storage.objects.list` calls to be permitted at all — a List's resource
                            // name is the *bucket*, never an object, so the object-prefix clause alone
                            // silently denies every List (found live: the gcs backend's "get existing
                            // workspaces" step 403'd with the object-prefix-only condition, even though
                            // objectAdmin covers list). Matches GCP's own documented dual-clause pattern.
                            'expression' => "resource.name.startsWith('projects/_/buckets/{$bucket}/objects/{$tenantPrefix}') || "
                                ."api.getAttribute('storage.googleapis.com/objectListPrefix', '').startsWith('{$tenantPrefix}')",
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::asForm()->post(self::STS_ENDPOINT, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $broadAccessToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'options' => json_encode($accessBoundary, JSON_THROW_ON_ERROR),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("popcorn/tofu: CAB downscoping failed ({$response->status()}): {$response->body()}");
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('popcorn/tofu: CAB downscoping returned no access_token.');
        }

        return $token;
    }
}
