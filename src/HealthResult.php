<?php

namespace Splicewire\Beam\Provision;

final readonly class HealthResult
{
    /**
     * @param  string  $status  'healthy' | 'unhealthy' | 'unknown'
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $status,
        public ?string $url,
        public array $raw = [],
    ) {}
}
