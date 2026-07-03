<?php

namespace Tests\Feature\Api;

use App\Enums\ProviderType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_require_authentication()
    {
        $this->getJson('/api/products')
            ->assertUnauthorized()
            ->assertJson(['success' => false, 'error' => ['code' => 'UNAUTHENTICATED']]);
    }

    public function test_it_returns_paginated_products_with_meta()
    {
        Sanctum::actingAs(User::factory()->create());
        Product::factory()->count(3)->create();

        $this->getJson('/api/products?per_page=2')
            ->assertOk()
            ->assertJson(['success' => true, 'meta' => ['page' => 1, 'per_page' => 2, 'total' => 3]])
            ->assertJsonCount(2, 'data');
    }

    public function test_the_resource_hides_internal_fields()
    {
        Sanctum::actingAs(User::factory()->create());
        Product::factory()->create();

        $response = $this->getJson('/api/products');

        $response->assertOk();
        $product = $response->json('data.0');
        $this->assertArrayNotHasKey('data_hash', $product);
        $this->assertArrayNotHasKey('raw_payload', $product);
    }

    public function test_it_filters_by_provider_and_stock()
    {
        Sanctum::actingAs(User::factory()->create());
        Product::factory()->forProvider(ProviderType::DummyJson)->create(['stock' => 0]);
        Product::factory()->forProvider(ProviderType::FakeStore)->create(['stock' => 7]);

        $this->getJson('/api/products?provider=fakestore&in_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider_type', 'fakestore');
    }

    public function test_it_rejects_an_oversized_per_page()
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products?per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_it_rejects_a_non_allowlisted_sort_column()
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products?sort=id')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
