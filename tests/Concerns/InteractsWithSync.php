<?php

namespace Tests\Concerns;

use App\Sync\Support\Sleeper;
use Illuminate\Support\Facades\Http;

trait InteractsWithSync
{
    /**
     * Replace the real Sleeper with a no-op so throttling/backoff never blocks
     * the test suite.
     */
    protected function fakeSleeper(): void
    {
        $this->app->instance(Sleeper::class, new class extends Sleeper
        {
            public function seconds(float $seconds): void {}
        });
    }

    /**
     * Fake the DummyJSON products endpoint with an enveloped response.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function fakeDummyJson(array $products): void
    {
        Http::fake([
            'dummyjson.com/*' => Http::response($this->dummyJsonEnvelope($products)),
        ]);
    }

    /**
     * DummyJSON response envelope for a set of products.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    protected function dummyJsonEnvelope(array $products): array
    {
        return [
            'products' => $products,
            'total' => count($products),
            'skip' => 0,
            'limit' => 100,
        ];
    }

    /**
     * Fake the FakeStore products endpoint with a bare array response.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function fakeFakeStore(array $products): void
    {
        Http::fake([
            'fakestoreapi.com/*' => Http::response($products),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dummyJsonProduct(int $id, string $title = 'Widget', float $price = 9.99, int $stock = 5): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'price' => $price,
            'stock' => $stock,
            'description' => 'Description for '.$title,
        ];
    }
}
