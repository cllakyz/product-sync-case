<?php

namespace Tests\Feature\Sync;

use App\Enums\AlertType;
use App\Enums\ProviderType;
use App\Models\DeadLetterJob;
use App\Models\SyncAlert;
use App\Sync\Services\AlertManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AlertManagerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): AlertManager
    {
        return new AlertManager(Cache::store());
    }

    public function test_sync_failure_alert_fires_only_at_the_streak_threshold()
    {
        config(['sync.alerts.sync_failure_streak' => 3]);
        $manager = $this->manager();
        $provider = ProviderType::DummyJson;

        $manager->syncFailed($provider);
        $manager->syncFailed($provider);
        $this->assertDatabaseCount('sync_alerts', 0);

        $manager->syncFailed($provider); // third → alert

        $this->assertDatabaseHas('sync_alerts', [
            'alert_type' => AlertType::SyncFailure->value,
            'provider_type' => $provider->value,
            'severity' => 'critical',
        ]);
    }

    public function test_alerts_are_debounced_within_the_window()
    {
        config(['sync.alerts.sync_failure_streak' => 1, 'sync.alerts.debounce_minutes' => 5]);
        $manager = $this->manager();
        $provider = ProviderType::DummyJson;

        $manager->syncFailed($provider); // fires
        $manager->syncFailed($provider); // debounced

        $this->assertSame(1, SyncAlert::where('alert_type', AlertType::SyncFailure->value)->count());
    }

    public function test_success_resets_the_failure_streak()
    {
        config(['sync.alerts.sync_failure_streak' => 2]);
        $manager = $this->manager();
        $provider = ProviderType::DummyJson;

        $manager->syncFailed($provider);
        $manager->syncSucceeded($provider);
        $manager->syncFailed($provider); // streak back to 1, no alert

        $this->assertDatabaseCount('sync_alerts', 0);
    }

    public function test_dlq_depth_alert_fires_above_threshold()
    {
        config(['sync.alerts.dlq_threshold' => 2]);
        DeadLetterJob::factory()->count(3)->create();

        $this->manager()->evaluateDlqDepth();

        $this->assertDatabaseHas('sync_alerts', [
            'alert_type' => AlertType::DlqThreshold->value,
        ]);
    }
}
