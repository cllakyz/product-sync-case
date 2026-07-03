<?php

namespace App\Sync\Data;

use App\Enums\ProviderType;

/**
 * Normalized product record produced by a provider adapter.
 *
 * Adapters guarantee the invariants documented in SPEC §3.2: a non-empty
 * external id and name, a non-negative price, and a non-negative or null stock.
 * Records that cannot satisfy these are skipped upstream, never constructed.
 */
final readonly class ProductData
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public ProviderType $providerType,
        public string $externalId,
        public string $name,
        public float $price,
        public ?int $stock,
        public ?string $description,
        public array $rawPayload = [],
    ) {}
}
