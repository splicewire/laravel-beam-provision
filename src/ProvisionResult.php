<?php

namespace Splicewire\Beam\Provision;

final readonly class ProvisionResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $appId,
        public ?string $url,
        public string $status,
        public array $raw = [],
    ) {}
}
