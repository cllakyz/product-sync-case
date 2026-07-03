<?php

namespace Database\Factories;

use App\Enums\AlertType;
use App\Enums\ProviderType;
use App\Models\SyncAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncAlert>
 */
class SyncAlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(AlertType::cases());

        return [
            'alert_type' => $type,
            'severity' => $type->severity(),
            'provider_type' => ProviderType::DummyJson,
            'details' => ['count' => fake()->numberBetween(1, 20)],
            'notified_at' => now(),
        ];
    }
}
