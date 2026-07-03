<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Support\ApiResponse;
use App\Sync\Providers\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends Controller
{
    /**
     * Dependency health probe. Public, but exposes no sensitive detail (SPEC §9.7).
     */
    public function index(ProviderRegistry $registry): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo()),
            'redis' => $this->check(fn () => Cache::store()->put('health:probe', 1, 5)),
            'queue' => $this->check(fn () => Queue::size('sync')),
        ];

        $healthy = ! in_array('fail', $checks, true);

        $lastSync = [];

        foreach ($registry->enabled() as $provider) {
            $lastSync[$provider->value] = SyncLog::query()
                ->where('provider_type', $provider->value)
                ->whereNotNull('completed_at')
                ->latest('completed_at')
                ->value('completed_at')?->toIso8601String();
        }

        return ApiResponse::success(
            data: [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'queue_backlog' => $this->queueBacklog(),
                'last_sync' => $lastSync,
            ],
            status: $healthy ? 200 : 503,
        );
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function queueBacklog(): int
    {
        try {
            return Queue::size('sync');
        } catch (Throwable) {
            return 0;
        }
    }
}
