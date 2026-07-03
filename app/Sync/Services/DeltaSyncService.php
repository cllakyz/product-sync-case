<?php

namespace App\Sync\Services;

use App\Enums\ProviderType;
use App\Models\Product;
use App\Sync\Data\DeltaSyncResult;
use App\Sync\Data\ProductData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hash-based delta sync (SPEC §5–6, §10.2). Applies a stream of normalized
 * products to the local catalogue: inserting new records, updating only those
 * whose hash changed, restoring previously soft-deleted ones, and — separately —
 * soft-deleting records the provider no longer returns (guarded against mass
 * deletion). All writes are idempotent via the (provider_type, external_id) key.
 */
class DeltaSyncService
{
    private const NAME_MAX = 255;

    private const DESCRIPTION_MAX = 60000;

    // DECIMAL(12,2) upper bound and unsigned INT upper bound.
    private const PRICE_MAX = 9_999_999_999.99;

    private const STOCK_MAX = 4_294_967_295;

    public function __construct(private readonly ProductHasher $hasher) {}

    /**
     * Apply a batch of products, updating the result accumulator in place.
     *
     * @param  iterable<ProductData>  $products
     */
    public function applyBatch(iterable $products, DeltaSyncResult $result): void
    {
        foreach ($products as $product) {
            $attributes = $this->normalizeForStorage($product);

            if ($attributes === null) {
                $result->failed++;

                continue;
            }

            // Track every valid external id we saw, even unchanged ones, so the
            // deletion pass only removes genuinely absent products.
            $result->seenExternalIds[] = $product->externalId;

            $this->upsert($product, $attributes, $result);
        }
    }

    /**
     * Soft-delete active products the provider no longer returns. Cancelled if
     * it would remove more than the configured guard ratio of the catalogue
     * (protects against a provider outage wiping everything — SPEC E18).
     */
    public function reconcileDeletions(ProviderType $provider, DeltaSyncResult $result): void
    {
        $activeCount = Product::query()
            ->where('provider_type', $provider->value)
            ->whereNull('deleted_at')
            ->count();

        if ($activeCount === 0) {
            return;
        }

        $staleQuery = Product::query()
            ->where('provider_type', $provider->value)
            ->whereNull('deleted_at')
            ->when(
                $result->seenExternalIds !== [],
                fn ($q) => $q->whereNotIn('external_id', $result->seenExternalIds),
            );

        $staleCount = (clone $staleQuery)->count();

        if ($staleCount === 0) {
            return;
        }

        $ratio = (float) config('sync.delete_guard_ratio', 0.5);

        if (($staleCount / $activeCount) > $ratio) {
            $result->deleteGuardTripped = true;

            Log::warning('sync.delete_guard_tripped', [
                'provider' => $provider->value,
                'active' => $activeCount,
                'stale' => $staleCount,
                'ratio' => round($staleCount / $activeCount, 3),
                'guard' => $ratio,
            ]);

            return;
        }

        $result->deleted = $staleQuery->update(['deleted_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(ProductData $product, array $attributes, DeltaSyncResult $result): void
    {
        $hash = $this->hasher->hash($product);

        DB::transaction(function () use ($product, $attributes, $hash, $result): void {
            /** @var Product|null $existing */
            $existing = Product::withTrashed()
                ->where('provider_type', $product->providerType->value)
                ->where('external_id', $product->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                Product::create([
                    'provider_type' => $product->providerType,
                    'external_id' => $product->externalId,
                    ...$attributes,
                    'data_hash' => $hash,
                    'last_synced_at' => now(),
                ]);

                $result->added++;

                return;
            }

            // Unchanged: only bump last_synced_at (updated_at stays put).
            if (! $existing->trashed() && $existing->data_hash === $hash) {
                Product::withoutTimestamps(function () use ($existing): void {
                    $existing->forceFill(['last_synced_at' => now()])->save();
                });
                $result->unchanged++;

                return;
            }

            // Changed or previously deleted (restore): apply fields + new hash.
            $existing->fill([
                ...$attributes,
                'data_hash' => $hash,
                'last_synced_at' => now(),
            ]);

            if ($existing->trashed()) {
                $existing->deleted_at = null;
            }

            $existing->save();
            $result->updated++;
        });
    }

    /**
     * Enforce storage constraints (SPEC §10.2). Returns the persistable
     * attribute array, or null when the record must be skipped (overflow).
     *
     * @return array<string, mixed>|null
     */
    private function normalizeForStorage(ProductData $product): ?array
    {
        if ($product->price > self::PRICE_MAX) {
            $this->warnInvalid($product, 'price overflow');

            return null;
        }

        if ($product->stock !== null && $product->stock > self::STOCK_MAX) {
            $this->warnInvalid($product, 'stock overflow');

            return null;
        }

        $name = $this->truncate($product->name, self::NAME_MAX, $product, 'name');
        $description = $product->description === null
            ? null
            : $this->truncate($product->description, self::DESCRIPTION_MAX, $product, 'description');

        return [
            'name' => $name,
            'price' => $product->price,
            'stock' => $product->stock,
            'description' => $description,
            'raw_payload' => $product->rawPayload,
        ];
    }

    private function truncate(string $value, int $max, ProductData $product, string $field): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        Log::warning('sync.field_truncated', [
            'provider' => $product->providerType->value,
            'external_id' => $product->externalId,
            'field' => $field,
        ]);

        return mb_substr($value, 0, $max);
    }

    private function warnInvalid(ProductData $product, string $reason): void
    {
        Log::warning('sync.record_rejected', [
            'provider' => $product->providerType->value,
            'external_id' => $product->externalId,
            'reason' => $reason,
        ]);
    }
}
