<?php

namespace Tests\Feature\Api;

use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Jobs\SyncProviderJob;
use App\Models\DeadLetterJob;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_trigger_validates_the_provider()
    {
        $this->postJson('/api/sync/trigger', ['provider' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_trigger_queues_a_sync_and_returns_202()
    {
        $this->postJson('/api/sync/trigger', ['provider' => 'dummyjson'])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queued.0.provider', 'dummyjson');

        Queue::assertPushed(SyncProviderJob::class);
        $this->assertDatabaseHas('sync_logs', [
            'provider_type' => 'dummyjson',
            'status' => SyncStatus::Pending->value,
            'trigger_type' => TriggerType::Manual->value,
        ]);
    }

    public function test_trigger_returns_409_when_a_sync_is_already_active()
    {
        SyncLog::factory()->running()->forProvider(ProviderType::DummyJson)->create();

        $this->postJson('/api/sync/trigger', ['provider' => 'dummyjson'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SYNC_IN_PROGRESS');

        Queue::assertNotPushed(SyncProviderJob::class);
    }

    public function test_history_is_paginated()
    {
        SyncLog::factory()->count(3)->create();

        $this->getJson('/api/sync/history?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_status_returns_active_and_last_completed()
    {
        SyncLog::factory()->forProvider(ProviderType::DummyJson)->create(['status' => SyncStatus::Completed]);

        $this->getJson('/api/sync/status')
            ->assertOk()
            ->assertJsonStructure(['data' => ['active', 'last_completed']]);
    }

    public function test_failed_jobs_are_listed()
    {
        DeadLetterJob::factory()->count(2)->create();

        $this->getJson('/api/sync/failed-jobs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_retry_requeues_a_dead_letter_job()
    {
        $dlq = DeadLetterJob::factory()->create();

        $this->postJson("/api/sync/retry/{$dlq->id}")
            ->assertStatus(202)
            ->assertJsonPath('data.status', DeadLetterStatus::Retrying->value);

        Queue::assertPushed(SyncProviderJob::class);
        $this->assertSame(DeadLetterStatus::Retrying, $dlq->refresh()->status);
    }

    public function test_retry_rejects_a_non_retriable_job()
    {
        $dlq = DeadLetterJob::factory()->resolved()->create();

        $this->postJson("/api/sync/retry/{$dlq->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOB_NOT_RETRIABLE');
    }

    public function test_retry_returns_404_for_a_missing_job()
    {
        $this->postJson('/api/sync/retry/99999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
