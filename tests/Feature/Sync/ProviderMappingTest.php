<?php

namespace Tests\Feature\Sync;

use App\Enums\ProviderType;
use App\Sync\Providers\ProviderRegistry;
use Tests\Concerns\InteractsWithSync;
use Tests\TestCase;

class ProviderMappingTest extends TestCase
{
    use InteractsWithSync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSleeper();
    }

    private function registry(): ProviderRegistry
    {
        return app(ProviderRegistry::class);
    }

    public function test_dummyjson_maps_and_yields_products()
    {
        $this->fakeDummyJson([
            $this->dummyJsonProduct(1, 'Alpha', 10.0, 3),
            $this->dummyJsonProduct(2, 'Beta', 20.5, 0),
        ]);

        $provider = $this->registry()->make(ProviderType::DummyJson);
        $products = iterator_to_array($provider->fetchAll());

        $this->assertCount(2, $products);
        $this->assertSame('1', $products[0]->externalId);
        $this->assertSame('Alpha', $products[0]->name);
        $this->assertSame(10.0, $products[0]->price);
        $this->assertSame(3, $products[0]->stock);
        $this->assertSame(0, $provider->skippedCount());
        $this->assertGreaterThanOrEqual(1, $provider->requestCount());
    }

    public function test_dummyjson_skips_invalid_records()
    {
        $this->fakeDummyJson([
            $this->dummyJsonProduct(1, 'Valid'),
            ['id' => 2, 'title' => '', 'price' => 5, 'stock' => 1],        // empty name
            ['id' => 3, 'title' => 'NoPrice', 'price' => 'abc', 'stock' => 1], // non-numeric price
        ]);

        $provider = $this->registry()->make(ProviderType::DummyJson);
        $products = iterator_to_array($provider->fetchAll());

        $this->assertCount(1, $products);
        $this->assertSame(2, $provider->skippedCount());
    }

    public function test_fakestore_missing_stock_is_null()
    {
        $this->fakeFakeStore([
            ['id' => 1, 'title' => 'Gamma', 'price' => 12.0, 'description' => 'no stock field'],
        ]);

        $provider = $this->registry()->make(ProviderType::FakeStore);
        $products = iterator_to_array($provider->fetchAll());

        $this->assertCount(1, $products);
        $this->assertNull($products[0]->stock);
        $this->assertSame('Gamma', $products[0]->name);
    }

    public function test_negative_stock_is_clamped_to_zero()
    {
        $this->fakeDummyJson([
            ['id' => 1, 'title' => 'Neg', 'price' => 5, 'stock' => -4, 'description' => 'x'],
        ]);

        $provider = $this->registry()->make(ProviderType::DummyJson);
        $products = iterator_to_array($provider->fetchAll());

        $this->assertSame(0, $products[0]->stock);
    }
}
