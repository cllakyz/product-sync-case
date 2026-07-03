<?php

namespace App\Sync\Providers;

use App\Enums\ProviderType;
use App\Sync\Data\ProductData;

/**
 * DummyJSON adapter (https://dummyjson.com/products).
 *
 * Responses are enveloped: { products: [...], total, skip, limit }. Paging uses
 * limit/skip. Products expose id, title, price, stock, description.
 */
class DummyJsonProvider extends AbstractHttpProvider
{
    public function type(): ProviderType
    {
        return ProviderType::DummyJson;
    }

    public function fetchAll(): iterable
    {
        $limit = max(1, (int) ($this->config['page_size'] ?? 30));
        $skip = 0;

        do {
            $payload = $this->get('/products', [
                'limit' => $limit,
                'skip' => $skip,
                'select' => 'title,price,stock,description',
            ]);

            $products = $payload['products'] ?? [];
            $total = (int) ($payload['total'] ?? 0);

            foreach ($products as $raw) {
                $product = $this->mapRecord((array) $raw);

                if ($product !== null) {
                    yield $product;
                }
            }

            $skip += $limit;
        } while ($skip < $total && $products !== []);
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
            stock: $raw['stock'] ?? null,
            description: $raw['description'] ?? null,
            raw: $raw,
        );
    }
}
