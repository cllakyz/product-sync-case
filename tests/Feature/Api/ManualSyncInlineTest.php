<?php

namespace Tests\Feature\Api;

use App\Enums\ProviderType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithSync;
use Tests\TestCase;

class ManualSyncInlineTest extends TestCase
{
    use InteractsWithSync;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSleeper();

        config([
            'queue.default' => 'database',
            'sync.job.inline_manual_database_queue' => true,
        ]);

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_manual_dashboard_trigger_runs_inline_for_local_database_queue(): void
    {
        $this->fakeDummyJson([
            $this->dummyJsonProduct(1, 'Inline Alpha'),
            $this->dummyJsonProduct(2, 'Inline Beta'),
        ]);

        $this->postJson('/api/sync/trigger', ['provider' => ProviderType::DummyJson->value])
            ->assertStatus(202)
            ->assertJsonPath('data.queued.0.provider', ProviderType::DummyJson->value);

        $this->assertSame(2, Product::count());
        $this->assertDatabaseHas('sync_logs', [
            'provider_type' => ProviderType::DummyJson->value,
            'status' => 'completed',
            'products_added' => 2,
        ]);
    }
}
