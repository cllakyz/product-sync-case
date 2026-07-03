<?php

namespace Database\Factories;

use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncLog>
 */
class SyncLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(1, 120));
        $completedAt = (clone $startedAt)->addSeconds(fake()->numberBetween(2, 30));

        return [
            'provider_type' => ProviderType::DummyJson,
            'trigger_type' => TriggerType::Scheduled,
            'status' => SyncStatus::Completed,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'duration_ms' => $startedAt->diffInMilliseconds($completedAt),
            'products_added' => fake()->numberBetween(0, 10),
            'products_updated' => fake()->numberBetween(0, 20),
            'products_deleted' => fake()->numberBetween(0, 5),
            'products_unchanged' => fake()->numberBetween(0, 100),
            'products_failed' => 0,
            'api_requests' => fake()->numberBetween(1, 10),
            'error_message' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncStatus::Running,
            'started_at' => now(),
            'completed_at' => null,
            'duration_ms' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SyncStatus::Failed,
            'error_message' => 'Sync failed after 3 attempts.',
        ]);
    }

    public function forProvider(ProviderType $provider): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider_type' => $provider,
        ]);
    }
}
