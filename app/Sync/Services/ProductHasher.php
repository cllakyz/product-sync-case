<?php

namespace App\Sync\Services;

use App\Sync\Data\ProductData;
use Normalizer;

/**
 * Deterministic SHA-256 fingerprint of a product's business fields (SPEC §5).
 * Only name, price, stock and description participate — identity and timestamps
 * are excluded. Normalization makes the hash stable across platforms and across
 * "19.99" vs 19.99 style representation differences.
 */
class ProductHasher
{
    public function hash(ProductData $product): string
    {
        $canonical = json_encode([
            'name' => $this->normalizeText($product->name),
            'price' => number_format($product->price, 2, '.', ''),
            'stock' => $product->stock ?? '__NULL__',
            'description' => $this->normalizeText($product->description ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /**
     * Trim, collapse internal whitespace, and NFC-normalize unicode.
     */
    private function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        return $value;
    }
}
