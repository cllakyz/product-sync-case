<?php

namespace Database\Factories;

use App\Enums\ProviderType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_type' => ProviderType::DummyJson,
            'external_id' => (string) fake()->unique()->numberBetween(1, 1_000_000),
            'name' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 1, 1000),
            'stock' => fake()->numberBetween(0, 500),
            'description' => fake()->sentence(),
            'data_hash' => hash('sha256', Str::random(40)),
            'raw_payload' => [],
            'last_synced_at' => now(),
        ];
    }

    public function forProvider(ProviderType $provider): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider_type' => $provider,
        ]);
    }

    public function deleted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deleted_at' => now(),
        ]);
    }
}
