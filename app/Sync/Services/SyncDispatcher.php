<?php

namespace App\Sync\Services;

use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Jobs\SyncProviderJob;
use App\Models\SyncLog;
use Throwable;

/**
 * Entry point for starting syncs (used by the API, the scheduler command and
 * manual retries). Creates a pending SyncLog and queues the job, refusing to
 * start a second run while one is already active for the same provider.
 */
class SyncDispatcher
{
    /**
     * Queue a sync for a provider. Returns the pending SyncLog, or null when a
     * sync for that provider is already pending/running (SPEC §6.2 / 409).
     */
    public function dispatch(
        ProviderType $provider,
        TriggerType $trigger = TriggerType::Scheduled,
        bool $force = false,
    ): ?SyncLog {
        if ($this->hasActiveRun($provider)) {
            return null;
        }

        $log = SyncLog::create([
            'provider_type' => $provider,
            'trigger_type' => $trigger,
            'status' => SyncStatus::Pending,
        ]);

        if ($this->shouldRunInline($trigger)) {
            try {
                SyncProviderJob::dispatchSync($provider, $log->id, $trigger, $force);
            } catch (Throwable) {
                // The sync queue marks the log failed and records DLQ state
                // before rethrowing; the dashboard can report that via polling.
            }
        } else {
            SyncProviderJob::dispatch($provider, $log->id, $trigger, $force);
        }

        return $log;
    }

    /**
     * Whether a pending or running sync already exists for the provider.
     */
    public function hasActiveRun(ProviderType $provider): bool
    {
        return SyncLog::query()
            ->active()
            ->where('provider_type', $provider->value)
            ->exists();
    }

    private function shouldRunInline(TriggerType $trigger): bool
    {
        return $trigger === TriggerType::Manual
            && config('queue.default') === 'database'
            && (bool) config('sync.job.inline_manual_database_queue', true);
    }
}
