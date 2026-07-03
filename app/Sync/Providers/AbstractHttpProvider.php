<?php

namespace App\Sync\Providers;

use App\Sync\Contracts\ProductProvider;
use App\Sync\Data\ProductData;
use App\Sync\Http\SyncHttpClient;
use Illuminate\Support\Facades\Log;

/**
 * Shared HTTP-provider behaviour: request/skip accounting and lenient,
 * provider-agnostic record validation. Subclasses implement paging (fetchAll)
 * and the raw→normalized field extraction (buildFromRaw).
 */
abstract class AbstractHttpProvider implements ProductProvider
{
    protected int $requestCount = 0;

    protected int $skippedCount = 0;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly SyncHttpClient $client,
        protected readonly array $config,
    ) {}

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }

    /**
     * Throttled GET against this provider's base URL, counting the request.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        $json = $this->client->getJson($this->type(), (string) $this->config['base_url'], $path, $query);
        $this->requestCount++;

        return $json;
    }

    /**
     * Validate and normalize a raw record into a DTO. Returns null (and counts a
     * skip) when the record fails provider-level validity checks (SPEC §10.2):
     * missing id/name or a non-numeric/negative price. Stock is clamped to >= 0;
     * a missing stock is left null for the delta layer's missing-stock policy.
     *
     * @param  array<string, mixed>  $raw  original record, stored for audit
     */
    protected function buildFromRaw(
        int|string|null $externalId,
        mixed $name,
        mixed $price,
        mixed $stock,
        mixed $description,
        array $raw,
    ): ?ProductData {
        $externalId = $externalId === null ? '' : trim((string) $externalId);
        $name = is_string($name) ? trim($name) : '';

        if ($externalId === '' || $name === '') {
            return $this->skip('missing id or name', $raw);
        }

        if (! is_numeric($price)) {
            return $this->skip('non-numeric price', $raw);
        }

        $price = (float) $price;

        if ($price < 0) {
            return $this->skip('negative price', $raw);
        }

        $normalizedStock = null;

        if ($stock !== null && is_numeric($stock)) {
            $normalizedStock = max(0, (int) $stock);
        }

        return new ProductData(
            providerType: $this->type(),
            externalId: $externalId,
            name: $name,
            price: $price,
            stock: $normalizedStock,
            description: is_string($description) ? $description : null,
            rawPayload: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function skip(string $reason, array $raw): null
    {
        $this->skippedCount++;

        Log::warning('sync.record_skipped', [
            'provider' => $this->type()->value,
            'reason' => $reason,
            'external_id' => $raw['id'] ?? null,
        ]);

        return null;
    }
}
