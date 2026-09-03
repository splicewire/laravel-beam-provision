<?php

namespace Splicewire\Beam\Provision;

final readonly class ProvisionResult
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  ?bool  $sandboxed  Whether the mechanism that produced this result ran under enforced
     *                            isolation. Three-state on purpose: `true` enforced, `false` ran
     *                            with the Grant advisory only, `null` = isolation is not a concept
     *                            for this destination (a vendor CLI, say). Defaulting it `true`
     *                            would be a claim nobody made; defaulting it `false` would libel a
     *                            destination that never had a Runner. Same idiom as `AGENTS.md`'s
     *                            three-state publish gate — an omission and a decision must not be
     *                            spelled identically.
     */
    public function __construct(
        public string $appId,
        public ?string $url,
        public string $status,
        public array $raw = [],
        public ?bool $sandboxed = null,
    ) {}
}
