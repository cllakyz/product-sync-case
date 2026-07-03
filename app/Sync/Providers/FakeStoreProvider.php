<?php

namespace App\Sync\Providers;

use App\Enums\ProviderType;
use App\Sync\Data\ProductData;

/**
 * FakeStore adapter (https://fakestoreapi.com/products).
 *
 * Responses are a bare JSON array with no paging envelope, so the whole
 * catalogue arrives in one request. Products have no `stock` field — the missing
 * value is left null here and resolved by the delta layer's missing-stock policy.
 */
class FakeStoreProvider extends AbstractHttpProvider
{
    public function type(): ProviderType
    {
        return ProviderType::FakeStore;
    }

    public function fetchAll(): iterable
    {
        $records = $this->get('/products');

        foreach ($records as $raw) {
            $product = $this->mapRecord((array) $raw);

            if ($product !== null) {
                yield $product;
            }
        }
    }

    public function fetchOne(string $externalId): ?ProductData
    {
        $raw = $this->get('/products/'.rawurlencode($externalId));

        return $raw === [] ? null : $this->mapRecord($raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapRecord(array $raw): ?ProductData
    {
        return $this->buildFromRaw(
            externalId: $raw['id'] ?? null,
            name: $raw['title'] ?? null,
            price: $raw['price'] ?? null,
            stock: $raw['stock'] ?? null, // absent for FakeStore -> null
            description: $raw['description'] ?? null,
            raw: $raw,
        );
    }
}
