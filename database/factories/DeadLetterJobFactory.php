<?php

namespace Database\Factories;

use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use App\Jobs\SyncProviderJob;
use App\Models\DeadLetterJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeadLetterJob>
 */
class DeadLetterJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_type' => class_basename(SyncProviderJob::class),
            'provider_type' => ProviderType::DummyJson,
            'payload' => [
                'provider' => ProviderType::DummyJson->value,
                'trigger' => 'manual',
            ],
            'exception' => 'RuntimeException: provider request failed',
            'retry_count' => 3,
            'status' => DeadLetterStatus::Pending,
            'failed_at' => now(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeadLetterStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function retrying(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DeadLetterStatus::Retrying,
            'last_retried_at' => now(),
        ]);
    }
}
