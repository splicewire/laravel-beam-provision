<?php

namespace Splicewire\Beam\Provision\Tofu;

/**
 * What to apply: a tenant id (drives the GCS state prefix, ticket 06's convention) and the
 * absolute path to that tenant's already-rendered root config directory. Rendering the
 * `.tf.json` itself is not this ticket's job — ticket 08/10's — this is purely the apply-time
 * handle.
 */
final readonly class TofuApplyRequest
{
    public function __construct(
        public string $tenantId,
        public string $rootConfigDir,
    ) {}
}
