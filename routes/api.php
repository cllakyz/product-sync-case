<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

// Public health probe.
Route::get('health', [HealthController::class, 'index'])->name('api.health');

Route::middleware('auth:sanctum')->group(function (): void {
    // Read endpoints — standard throttle.
    Route::middleware('throttle:'.config('sync.throttle.default'))->group(function (): void {
        Route::get('sync/status', [SyncController::class, 'status'])->name('api.sync.status');
        Route::get('sync/history', [SyncController::class, 'history'])->name('api.sync.history');
        Route::get('sync/failed-jobs', [SyncController::class, 'failedJobs'])->name('api.sync.failed-jobs');
        Route::get('products', [ProductController::class, 'index'])->name('api.products.index');
    });

    // State-changing endpoints — stricter throttle (they enqueue real work).
    Route::middleware('throttle:'.config('sync.throttle.trigger'))->group(function (): void {
        Route::post('sync/trigger', [SyncController::class, 'trigger'])->name('api.sync.trigger');
        Route::post('sync/retry/{jobId}', [SyncController::class, 'retry'])
            ->whereNumber('jobId')
            ->name('api.sync.retry');
    });
});
