<?php

namespace App\Jobs;

use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Models\SyncLog;
use App\Sync\Services\AlertManager;
use App\Sync\Services\DeadLetterService;
use App\Sync\Services\SyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one provider sync (SPEC §6, §8). Unique per provider so a second sync for
 * the same provider is never queued while one is active; retried with exponential
 * backoff and dead-lettered after the final attempt.
 */
class SyncProviderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(
        public ProviderType $provider,
        public int $syncLogId,
        public TriggerType $trigger = TriggerType::Scheduled,
        public bool $force = false,
    ) {
        $this->tries = (int) config('sync.job.tries', 3);
        $this->onQueue('sync');
    }

    /**
     * Exponential backoff between attempts (seconds): 1s, 2s, 4s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('sync.job.backoff', [1, 2, 4]);
    }

    /**
     * One in-flight sync per provider (dispatch-time guard).
     */
    public function uniqueId(): string
    {
        return "sync:{$this->provider->value}";
    }

    public function uniqueFor(): int
    {
        return (int) config('sync.job.unique_for_seconds', 600);
    }

    public function handle(SyncOrchestrator $orchestrator): void
    {
        $log = SyncLog::find($this->syncLogId);

        if ($log === null) {
            return;
        }

        $orchestrator->run($log, $this->force);
    }

    /**
     * Final attempt failed: dead-letter the job and advance the failure streak.
     */
    public function failed(Throwable $exception): void
    {
        app(DeadLetterService::class)->record(
            self::class,
            $this->provider,
            [
                'provider' => $this->provider->value,
                'trigger' => $this->trigger->value,
                'sync_log_id' => $this->syncLogId,
                'force' => $this->force,
            ],
            $exception,
            $this->attempts(),
        );

        app(AlertManager::class)->syncFailed($this->provider);

        SyncLog::where('id', $this->syncLogId)
            ->whereNotIn('status', [SyncStatus::Failed->value, SyncStatus::Completed->value])
            ->update([
                'status' => SyncStatus::Failed->value,
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
    }
}
