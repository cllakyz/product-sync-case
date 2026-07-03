<?php

namespace Tests\Feature\Sync;

use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Jobs\SyncProviderJob;
use App\Models\DeadLetterJob;
use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SyncProviderJobTest extends TestCase
{
    use RefreshDatabase;

    private function job(int $logId = 1): SyncProviderJob
    {
        return new SyncProviderJob(ProviderType::DummyJson, $logId, TriggerType::Manual);
    }

    public function test_it_retries_three_times_with_exponential_backoff()
    {
        $job = $this->job();

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 2, 4], $job->backoff());
    }

    public function test_it_is_unique_per_provider()
    {
        $this->assertSame('sync:dummyjson', $this->job()->uniqueId());
    }

    public function test_failure_dead_letters_the_job_and_marks_the_log_failed()
    {
        $log = SyncLog::create([
            'provider_type' => ProviderType::DummyJson,
            'trigger_type' => TriggerType::Manual,
            'status' => SyncStatus::Running,
        ]);

        $this->job($log->id)->failed(new RuntimeException('provider exploded'));

        $this->assertDatabaseHas('dead_letter_jobs', [
            'job_type' => 'SyncProviderJob',
            'provider_type' => ProviderType::DummyJson->value,
            'status' => DeadLetterStatus::Pending->value,
        ]);

        $this->assertSame(SyncStatus::Failed, $log->refresh()->status);
    }

    public function test_dead_letter_payload_is_sanitized_and_carries_no_trace_secrets()
    {
        $log = SyncLog::create([
            'provider_type' => ProviderType::DummyJson,
            'trigger_type' => TriggerType::Manual,
            'status' => SyncStatus::Running,
        ]);

        $this->job($log->id)->failed(new RuntimeException('boom'));

        $dlq = DeadLetterJob::first();
        $this->assertSame(ProviderType::DummyJson->value, $dlq->payload['provider']);
        $this->assertArrayNotHasKey('token', $dlq->payload);
    }
}
