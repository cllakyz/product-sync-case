<?php

namespace App\Sync\Services;

use App\Enums\SyncStatus;
use App\Models\SyncLog;
use App\Sync\Data\DeltaSyncResult;
use App\Sync\Exceptions\CircuitBreakerOpenException;
use App\Sync\Providers\ProviderRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Drives a single sync run end to end (SPEC §6.3): acquires the per-provider
 * lock, streams the provider through the delta service, reconciles deletions,
 * and records the outcome on the SyncLog. Deletions are skipped on partial runs
 * so an interrupted fetch never triggers false deletions.
 */
class SyncOrchestrator
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly DeltaSyncService $delta,
        private readonly CircuitBreaker $breaker,
        private readonly AlertManager $alerts,
    ) {}

    /**
     * Execute the run described by a (pending) SyncLog.
     *
     * @throws Throwable on hard failures, so the job layer can retry / dead-letter
     */
    public function run(SyncLog $log, bool $force = false): SyncLog
    {
        $provider = $log->provider_type;

        $lock = Cache::lock(
            "sync-run:{$provider->value}",
            (int) config('sync.job.unique_for_seconds', 600),
        );

        // Second guard (after ShouldBeUnique): never run two syncs for the same
        // provider concurrently. If the lock is held, this run is a no-op.
        if (! $lock->get()) {
            $log->update([
                'status' => SyncStatus::Skipped,
                'started_at' => now(),
                'completed_at' => now(),
                'duration_ms' => 0,
                'error_message' => 'Another sync run is already in progress for this provider.',
            ]);

            return $log->refresh();
        }

        $startedAt = now();
        $log->update(['status' => SyncStatus::Running, 'started_at' => $startedAt]);

        try {
            if ($force) {
                $this->breaker->reset($provider);
            }

            $adapter = $this->registry->make($provider);
            $result = new DeltaSyncResult;
            $completedFully = true;
            $errorMessage = null;

            try {
                $this->delta->applyBatch($adapter->fetchAll(), $result);
            } catch (CircuitBreakerOpenException $e) {
                // Controlled stop: the API is failing consistently. End the run
                // as partial (no throw, no retry) and raise an API_FAILURE alert.
                $completedFully = false;
                $errorMessage = $e->getMessage();
                $this->alerts->apiFailure($provider, $this->breaker->consecutiveFailures($provider));
            }

            // Only reconcile deletions when the fetch completed in full.
            if ($completedFully) {
                $this->delta->reconcileDeletions($provider, $result);
            }

            $status = (! $completedFully || $result->deleteGuardTripped)
                ? SyncStatus::Partial
                : SyncStatus::Completed;

            if ($status === SyncStatus::Partial && $errorMessage === null && $result->deleteGuardTripped) {
                $errorMessage = 'Delete guard tripped: mass deletion cancelled.';
            }

            $this->finalize($log, $status, $startedAt, $result, $adapter->requestCount(), $adapter->skippedCount(), $errorMessage);

            if ($status === SyncStatus::Completed) {
                $this->alerts->syncSucceeded($provider);
            } else {
                $this->alerts->syncFailed($provider);
            }

            return $log->refresh();
        } catch (Throwable $e) {
            // The failure streak is advanced once per dead-lettered sync in the
            // job's failed() hook — not per retry attempt — to avoid inflation.
            $this->finalize(
                $log,
                SyncStatus::Failed,
                $startedAt,
                new DeltaSyncResult,
                0,
                0,
                $e->getMessage(),
            );

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function finalize(
        SyncLog $log,
        SyncStatus $status,
        CarbonInterface $startedAt,
        DeltaSyncResult $result,
        int $requestCount,
        int $skippedCount,
        ?string $errorMessage,
    ): void {
        $completedAt = now();

        $log->update([
            'status' => $status,
            'completed_at' => $completedAt,
            'duration_ms' => $startedAt->diffInMilliseconds($completedAt),
            'products_added' => $result->added,
            'products_updated' => $result->updated,
            'products_deleted' => $result->deleted,
            'products_unchanged' => $result->unchanged,
            'products_failed' => $result->failed + $skippedCount,
            'api_requests' => $requestCount,
            'error_message' => $errorMessage,
        ]);
    }
}
