<?php

use App\Enums\ProviderType;
use App\Sync\Providers\DummyJsonProvider;
use App\Sync\Providers\FakeStoreProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | How often the scheduler dispatches a sync job per active provider. The
    | `sync:dispatch` command is bound to this interval in routes/console.php.
    |
    */

    'interval_minutes' => (int) env('SYNC_INTERVAL_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting & throttling
    |--------------------------------------------------------------------------
    |
    | Maximum outbound requests per second to a single provider. The engine
    | also spaces requests by (1000ms / max_rps) to stay well under the limit.
    |
    */

    'max_requests_per_second' => (int) env('SYNC_MAX_RPS', 5),

    /*
    |--------------------------------------------------------------------------
    | Job retry & backoff
    |--------------------------------------------------------------------------
    |
    | `tries` bounds the total attempts before a job is dead-lettered. `backoff`
    | is the per-attempt delay in seconds (exponential: 1s, 2s, 4s).
    |
    */

    'job' => [
        'tries' => (int) env('SYNC_JOB_TRIES', 3),
        'backoff' => array_map(
            'intval',
            explode(',', (string) env('SYNC_JOB_BACKOFF', '1,2,4')),
        ),
        // Hard ceiling (seconds from first attempt) after which retries stop.
        'retry_until_seconds' => (int) env('SYNC_JOB_RETRY_UNTIL', 600),
        // Lock TTL (seconds) protecting a provider from concurrent sync runs.
        'unique_for_seconds' => (int) env('SYNC_JOB_UNIQUE_FOR', 600),
        // Local database queues often run without a worker; manual dashboard
        // triggers should still complete instead of staying pending forever.
        'inline_manual_database_queue' => (bool) env('SYNC_INLINE_MANUAL_DATABASE_QUEUE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Consecutive failed requests that trip the breaker and stop the run. A
    | tripped provider is skipped for `cooldown_seconds` unless a sync is forced.
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('SYNC_CONSECUTIVE_FAILURE_LIMIT', 5),
        'cooldown_seconds' => (int) env('SYNC_BREAKER_COOLDOWN', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delta sync safeguards
    |--------------------------------------------------------------------------
    |
    | delete_guard_ratio: if a run would soft-delete more than this fraction of
    | a provider's active products, deletion is cancelled and the run is marked
    | partial (guards against a provider outage wiping the catalogue). E18.
    |
    | missing_stock_policy: how to treat a provider that omits stock (FakeStore):
    |   keep -> preserve existing value (0 on first insert)
    |   zero -> always 0
    |   null -> store null
    |
    */

    'delete_guard_ratio' => (float) env('SYNC_DELETE_GUARD_RATIO', 0.5),

    'missing_stock_policy' => env('SYNC_MISSING_STOCK_POLICY', 'keep'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */

    'http' => [
        'connect_timeout' => (int) env('HTTP_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('HTTP_REQUEST_TIMEOUT', 15),
        // Max backoff (seconds) for request-level 429/5xx retries.
        'max_backoff' => (int) env('HTTP_MAX_BACKOFF', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each provider maps a ProviderType to an adapter class and its settings.
    | Add a new provider by implementing ProductProvider and registering it here.
    |
    */

    'providers' => [

        ProviderType::DummyJson->value => [
            'enabled' => (bool) env('PROVIDER_DUMMYJSON_ENABLED', true),
            'class' => DummyJsonProvider::class,
            'base_url' => env('PROVIDER_DUMMYJSON_BASE_URL', 'https://dummyjson.com'),
            'page_size' => (int) env('PROVIDER_DUMMYJSON_PAGE_SIZE', 30),
        ],

        ProviderType::FakeStore->value => [
            'enabled' => (bool) env('PROVIDER_FAKESTORE_ENABLED', true),
            'class' => FakeStoreProvider::class,
            'base_url' => env('PROVIDER_FAKESTORE_BASE_URL', 'https://fakestoreapi.com'),
            'page_size' => (int) env('PROVIDER_FAKESTORE_PAGE_SIZE', 100),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    |
    | Thresholds for critical alerts. `debounce_minutes` prevents the same
    | (alert_type, provider) from notifying more than once per window.
    | webhook_url is reserved for the deferred notification channel.
    |
    */

    'alerts' => [
        'sync_failure_streak' => (int) env('ALERT_SYNC_FAILURE_STREAK', 3),
        'dlq_threshold' => (int) env('ALERT_DLQ_THRESHOLD', 10),
        'queue_backlog_threshold' => (int) env('ALERT_QUEUE_BACKLOG_THRESHOLD', 100),
        'debounce_minutes' => (int) env('ALERT_DEBOUNCE_MINUTES', 5),
        'webhook_url' => env('ALERT_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API throttling
    |--------------------------------------------------------------------------
    |
    | "requests,minutes" pairs. Trigger/retry endpoints use a stricter limit
    | since they enqueue real work.
    |
    */

    'throttle' => [
        'default' => env('API_THROTTLE_DEFAULT', '60,1'),
        'trigger' => env('API_THROTTLE_TRIGGER', '10,1'),
    ],

];
