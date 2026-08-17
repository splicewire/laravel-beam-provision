<?php

namespace Splicewire\Beam\Provision\Gcp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves a ready-to-use GCP access token for {@see \Splicewire\Beam\Provision\Tofu\TofuApplyDispatcher::apply()}
 * from an externally-minted OIDC identity token (tenant-database-upsell ticket 16's
 * credential-resolution mechanism) — no static GCP credential involved at any point. Two
 * hops, both short-lived and both freshly minted per call, matching this family's existing
 * ask-fresh-each-use discipline (see `laravel-satellite-training`'s `CloudCredential` contract):
 *
 *  1. STS token-exchange (RFC 8693): trade the caller's self-signed OIDC token for a
 *     federated GCP access token scoped to the Workload Identity Federation principal.
 *     Deliberately generic about who minted the subject token or how — this class only knows
 *     "OIDC token in, GCP-federated token out." `Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter`
 *     is one source; nothing here depends on that package, or on any specific issuer.
 *  2. IAM Credentials `generateAccessToken`: the federated token then impersonates a real
 *     service account — the same `roles/iam.serviceAccountTokenCreator` impersonation pattern
 *     `gcp-cloud-run-provisioning` ticket 02 uses for a human operator's own `gcloud` session;
 *     here the Workload Identity Federation principal is the trust anchor instead of a person.
 *
 * The federated token from step 1 is intentionally never returned to the caller — it's an
 * internal hop, scoped to nothing beyond what `generateAccessToken` itself needs.
 */
final class WorkloadIdentityCredentialResolver
{
    private const STS_ENDPOINT = 'https://sts.googleapis.com/v1/token';

    private const IAM_CREDENTIALS_BASE_URL = 'https://iamcredentials.googleapis.com/v1';

    private const CLOUD_PLATFORM_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const IMPERSONATED_TOKEN_LIFETIME = '3600s';

    /**
     * @param  string  $subjectToken  A short-lived, already-signed OIDC identity token (a JWT) whose
     *                                issuer the target Workload Identity Federation provider trusts.
     * @param  string  $workloadAudience  The full WIF provider resource name GCP expects as the
     *                                    exchange's `audience`:
     *                                    `//iam.googleapis.com/projects/{num}/locations/global/workloadIdentityPools/{pool}/providers/{provider}`.
     * @param  string  $serviceAccountEmail  The SA to impersonate — must grant the WIF principal
     *                                       `roles/iam.serviceAccountTokenCreator` (or `roles/iam.workloadIdentityUser`).
     * @return string A short-lived access token for `$serviceAccountEmail`.
     *
     * @throws RuntimeException on either hop's failure
     */
    public function resolve(string $subjectToken, string $workloadAudience, string $serviceAccountEmail): string
    {
        $federatedToken = $this->exchange($subjectToken, $workloadAudience);

        return $this->impersonate($federatedToken, $serviceAccountEmail);
    }

    private function exchange(string $subjectToken, string $workloadAudience): string
    {
        $response = Http::asForm()->post(self::STS_ENDPOINT, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'audience' => $workloadAudience,
            'scope' => self::CLOUD_PLATFORM_SCOPE,
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'subject_token' => $subjectToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:jwt',
        ]);

        if ($response->failed()) {
            throw new RuntimeException("beam-provision/gcp: Workload Identity Federation token exchange failed ({$response->status()}): {$response->body()}");
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('beam-provision/gcp: Workload Identity Federation token exchange returned no access_token.');
        }

        return $token;
    }

    private function impersonate(string $federatedToken, string $serviceAccountEmail): string
    {
        $response = Http::withToken($federatedToken)
            ->asJson()
            ->post(
                self::IAM_CREDENTIALS_BASE_URL."/projects/-/serviceAccounts/{$serviceAccountEmail}:generateAccessToken",
                [
                    'scope' => [self::CLOUD_PLATFORM_SCOPE],
                    'lifetime' => self::IMPERSONATED_TOKEN_LIFETIME,
                ],
            );

        if ($response->failed()) {
            throw new RuntimeException("beam-provision/gcp: impersonating `{$serviceAccountEmail}` failed ({$response->status()}): {$response->body()}");
        }

        $token = $response->json('accessToken');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException("beam-provision/gcp: generateAccessToken for `{$serviceAccountEmail}` returned no accessToken.");
        }

        return $token;
    }
}
