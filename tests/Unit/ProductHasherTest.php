<?php

namespace Tests\Unit;

use App\Enums\ProviderType;
use App\Sync\Data\ProductData;
use App\Sync\Services\ProductHasher;
use Tests\TestCase;

class ProductHasherTest extends TestCase
{
    private function product(
        string $name = 'Widget',
        float $price = 19.99,
        ?int $stock = 10,
        ?string $description = 'A widget',
    ): ProductData {
        return new ProductData(ProviderType::DummyJson, '1', $name, $price, $stock, $description);
    }

    public function test_identical_data_produces_identical_hash()
    {
        $hasher = new ProductHasher;

        $this->assertSame(
            $hasher->hash($this->product()),
            $hasher->hash($this->product()),
        );
    }

    public function test_hash_is_a_sha256_hex_string()
    {
        $hash = (new ProductHasher)->hash($this->product());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_changing_any_business_field_changes_the_hash()
    {
        $hasher = new ProductHasher;
        $base = $hasher->hash($this->product());

        $this->assertNotSame($base, $hasher->hash($this->product(name: 'Other')));
        $this->assertNotSame($base, $hasher->hash($this->product(price: 29.99)));
        $this->assertNotSame($base, $hasher->hash($this->product(stock: 5)));
        $this->assertNotSame($base, $hasher->hash($this->product(description: 'changed')));
    }

    public function test_price_representation_differences_do_not_change_hash()
    {
        $hasher = new ProductHasher;

        // 19.99 vs 19.990 vs "19.99"-derived float all normalize identically.
        $this->assertSame(
            $hasher->hash($this->product(price: 19.99)),
            $hasher->hash($this->product(price: 19.990)),
        );
    }

    public function test_whitespace_and_unicode_are_normalized()
    {
        $hasher = new ProductHasher;

        $this->assertSame(
            $hasher->hash($this->product(name: 'Widget')),
            $hasher->hash($this->product(name: '  Widget  ')),
        );

        $this->assertSame(
            $hasher->hash($this->product(name: 'a b')),
            $hasher->hash($this->product(name: "a\t b")),
        );
    }

    public function test_null_stock_differs_from_zero_stock()
    {
        $hasher = new ProductHasher;

        $this->assertNotSame(
            $hasher->hash($this->product(stock: null)),
            $hasher->hash($this->product(stock: 0)),
        );
    }
}
