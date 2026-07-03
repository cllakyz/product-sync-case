<?php

namespace Tests\Unit;

use App\Enums\ProviderType;
use App\Models\Product;
use App\Sync\Data\DeltaSyncResult;
use App\Sync\Data\ProductData;
use App\Sync\Services\DeltaSyncService;
use App\Sync\Services\ProductHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeltaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DeltaSyncService
    {
        return new DeltaSyncService(new ProductHasher);
    }

    private function dto(string $id, string $name = 'Widget', float $price = 9.99, ?int $stock = 5): ProductData
    {
        return new ProductData(ProviderType::DummyJson, $id, $name, $price, $stock, 'desc');
    }

    public function test_new_products_are_added()
    {
        $result = new DeltaSyncResult;

        $this->service()->applyBatch([$this->dto('1'), $this->dto('2')], $result);

        $this->assertSame(2, $result->added);
        $this->assertSame(2, Product::count());
        $this->assertEqualsCanonicalizing(['1', '2'], $result->seenExternalIds);
    }

    public function test_unchanged_products_are_not_updated()
    {
        $service = $this->service();

        $service->applyBatch([$this->dto('1')], new DeltaSyncResult);
        $original = Product::first();

        $second = new DeltaSyncResult;
        $service->applyBatch([$this->dto('1')], $second);

        $this->assertSame(1, $second->unchanged);
        $this->assertSame(0, $second->updated);
        $this->assertSame(1, Product::count());
        // updated_at is untouched for unchanged records.
        $this->assertEquals($original->updated_at, Product::first()->updated_at);
    }

    public function test_changed_products_are_updated_not_duplicated()
    {
        $service = $this->service();
        $service->applyBatch([$this->dto('1', price: 9.99)], new DeltaSyncResult);

        $result = new DeltaSyncResult;
        $service->applyBatch([$this->dto('1', price: 14.99)], $result);

        $this->assertSame(1, $result->updated);
        $this->assertSame(1, Product::count());
        $this->assertSame('14.99', Product::first()->price);
    }

    public function test_soft_deleted_products_are_restored_when_they_return()
    {
        $service = $this->service();
        $service->applyBatch([$this->dto('1')], new DeltaSyncResult);
        Product::first()->delete();

        $result = new DeltaSyncResult;
        $service->applyBatch([$this->dto('1')], $result);

        $this->assertSame(1, $result->updated);
        $this->assertNull(Product::first()->deleted_at);
    }

    public function test_missing_products_are_soft_deleted()
    {
        $service = $this->service();
        $service->applyBatch([$this->dto('1'), $this->dto('2'), $this->dto('3'), $this->dto('4')], new DeltaSyncResult);

        // Only 3 of 4 returned this run → 1 stale (25% < guard).
        $result = new DeltaSyncResult;
        $result->seenExternalIds = ['1', '2', '3'];
        $service->reconcileDeletions(ProviderType::DummyJson, $result);

        $this->assertSame(1, $result->deleted);
        $this->assertSoftDeleted('products', ['external_id' => '4']);
    }

    public function test_delete_guard_cancels_mass_deletion()
    {
        config(['sync.delete_guard_ratio' => 0.5]);
        $service = $this->service();
        $service->applyBatch([$this->dto('1'), $this->dto('2'), $this->dto('3'), $this->dto('4')], new DeltaSyncResult);

        // Provider returned nothing → would delete 100% (> 50% guard).
        $result = new DeltaSyncResult;
        $result->seenExternalIds = [];
        $service->reconcileDeletions(ProviderType::DummyJson, $result);

        $this->assertTrue($result->deleteGuardTripped);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(4, Product::whereNull('deleted_at')->count());
    }

    public function test_price_overflow_is_rejected_as_failed()
    {
        $result = new DeltaSyncResult;

        $this->service()->applyBatch([$this->dto('1', price: 1e12)], $result);

        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->added);
        $this->assertSame(0, Product::count());
    }
}
