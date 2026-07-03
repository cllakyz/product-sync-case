<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FailedJobsRequest;
use App\Http\Requests\Api\SyncHistoryRequest;
use App\Http\Requests\Api\TriggerSyncRequest;
use App\Http\Resources\DeadLetterJobResource;
use App\Http\Resources\SyncLogResource;
use App\Jobs\SyncProviderJob;
use App\Models\DeadLetterJob;
use App\Models\SyncLog;
use App\Support\ApiResponse;
use App\Sync\Providers\ProviderRegistry;
use App\Sync\Services\SyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncDispatcher $dispatcher,
        private readonly ProviderRegistry $registry,
    ) {}

    /**
     * Manually trigger a sync for one provider or all enabled providers.
     */
    public function trigger(TriggerSyncRequest $request): JsonResponse
    {
        $single = $request->providerType();
        $providers = $single !== null ? [$single] : $this->registry->enabled();

        $queued = [];
        $inProgress = [];

        foreach ($providers as $provider) {
            $log = $this->dispatcher->dispatch($provider, TriggerType::Manual, $request->forced());

            if ($log === null) {
                $inProgress[] = $provider->value;

                continue;
            }

            $queued[] = ['provider' => $provider->value, 'sync_log_id' => $log->id];
        }

        // A single explicitly-requested provider that is already running is a conflict.
        if ($single !== null && $queued === []) {
            return ApiResponse::error(
                'SYNC_IN_PROGRESS',
                "[{$single->value}] için zaten aktif bir senkronizasyon var.",
                409,
            );
        }

        return ApiResponse::success(
            data: ['queued' => $queued, 'in_progress' => $inProgress],
            message: 'Senkronizasyon kuyruğa alındı.',
            status: 202,
        );
    }

    /**
     * Active runs plus the latest completed run per provider.
     */
    public function status(Request $request): JsonResponse
    {
        $active = SyncLog::query()
            ->active()
            ->latest('started_at')
            ->get()
            ->map(fn (SyncLog $log): array => [
                'provider' => $log->provider_type->value,
                'status' => $log->status->value,
                'started_at' => $log->started_at?->toIso8601String(),
                'sync_log_id' => $log->id,
            ]);

        $lastCompleted = [];

        foreach ($this->registry->enabled() as $provider) {
            $log = SyncLog::query()
                ->where('provider_type', $provider->value)
                ->whereIn('status', [SyncStatus::Completed->value, SyncStatus::Partial->value])
                ->latest('completed_at')
                ->first();

            $lastCompleted[$provider->value] = $log !== null ? new SyncLogResource($log) : null;
        }

        return ApiResponse::success([
            'active' => $active,
            'last_completed' => $lastCompleted,
        ]);
    }

    /**
     * Paginated sync history, newest first.
     */
    public function history(SyncHistoryRequest $request): JsonResponse
    {
        $logs = SyncLog::query()
            ->when($request->input('provider'), fn ($q, $v) => $q->where('provider_type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate($request->perPage());

        return ApiResponse::paginated($logs, SyncLogResource::class, 'Senkronizasyon geçmişi.');
    }

    /**
     * Paginated dead-letter queue.
     */
    public function failedJobs(FailedJobsRequest $request): JsonResponse
    {
        $status = $request->statusFilter() ?? DeadLetterStatus::Pending;

        $jobs = DeadLetterJob::query()
            ->where('status', $status->value)
            ->latest('failed_at')
            ->paginate($request->perPage());

        return ApiResponse::paginated($jobs, DeadLetterJobResource::class, 'Başarısız job listesi.');
    }

    /**
     * Re-dispatch a dead-lettered job.
     */
    public function retry(int $jobId): JsonResponse
    {
        /** @var DeadLetterJob $deadLetter */
        $deadLetter = DeadLetterJob::findOrFail($jobId);

        if (! $deadLetter->isRetriable()) {
            return ApiResponse::error(
                'JOB_NOT_RETRIABLE',
                'Bu job şu anki durumunda yeniden denenemez.',
                409,
            );
        }

        $provider = $deadLetter->provider_type ?? ProviderType::from($deadLetter->payload['provider']);

        if ($this->dispatcher->hasActiveRun($provider)) {
            return ApiResponse::error(
                'SYNC_IN_PROGRESS',
                "[{$provider->value}] için zaten aktif bir senkronizasyon var.",
                409,
            );
        }

        $log = SyncLog::create([
            'provider_type' => $provider,
            'trigger_type' => TriggerType::Manual,
            'status' => SyncStatus::Pending,
        ]);

        SyncProviderJob::dispatch($provider, $log->id, TriggerType::Manual);

        $deadLetter->update([
            'status' => DeadLetterStatus::Retrying,
            'last_retried_at' => now(),
        ]);

        return ApiResponse::success(
            data: ['dead_letter_job_id' => $deadLetter->id, 'sync_log_id' => $log->id, 'status' => DeadLetterStatus::Retrying->value],
            message: 'Job yeniden kuyruğa alındı.',
            status: 202,
        );
    }
}
