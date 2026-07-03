<?php

namespace App\Sync\Http;

use App\Enums\ProviderType;
use App\Sync\Exceptions\CircuitBreakerOpenException;
use App\Sync\Exceptions\ProviderRequestException;
use App\Sync\Services\CircuitBreaker;
use App\Sync\Services\RateLimiter;
use App\Sync\Support\Sleeper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Outbound HTTP for provider adapters with the resilience concerns from
 * SPEC §7 folded in: per-provider throttling, request-level 429/5xx backoff,
 * and circuit-breaker accounting. Adapters stay focused on mapping.
 */
class SyncHttpClient
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly CircuitBreaker $breaker,
        private readonly Sleeper $sleeper,
    ) {}

    /**
     * Perform a throttled, retrying GET and return decoded JSON.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws CircuitBreakerOpenException when the breaker trips
     * @throws ProviderRequestException on non-retriable or exhausted failures
     */
    public function getJson(ProviderType $provider, string $baseUrl, string $path, array $query = []): array
    {
        $maxAttempts = max(1, (int) config('sync.http.request_retries', 3) + 1);

        for ($attempt = 1; ; $attempt++) {
            // Fail fast if a prior failure already opened the breaker.
            $this->breaker->guard($provider);
            $this->rateLimiter->throttle($provider);

            try {
                $response = Http::baseUrl($baseUrl)
                    ->connectTimeout((int) config('sync.http.connect_timeout', 5))
                    ->timeout((int) config('sync.http.timeout', 15))
                    ->acceptJson()
                    ->get($path, $query);
            } catch (ConnectionException $e) {
                // Network/timeout — retriable. May trip the breaker.
                $this->breaker->recordFailure($provider);

                if ($attempt >= $maxAttempts) {
                    throw new ProviderRequestException($provider, "Connection failed: {$e->getMessage()}", $e);
                }

                $this->sleeper->seconds($this->backoffSeconds($attempt, null));

                continue;
            }

            $status = $response->status();

            // 429 — respect Retry-After when present, otherwise exponential backoff.
            if ($status === 429) {
                $this->breaker->recordFailure($provider);

                if ($attempt >= $maxAttempts) {
                    throw new ProviderRequestException($provider, '429 Too Many Requests (retries exhausted)');
                }

                $this->sleeper->seconds($this->backoffSeconds($attempt, $response->header('Retry-After')));

                continue;
            }

            // 5xx — retriable server error.
            if ($response->serverError()) {
                $this->breaker->recordFailure($provider);

                if ($attempt >= $maxAttempts) {
                    throw new ProviderRequestException($provider, "Server error {$status} (retries exhausted)");
                }

                $this->sleeper->seconds($this->backoffSeconds($attempt, null));

                continue;
            }

            // Other 4xx — not retriable.
            if ($response->clientError()) {
                $this->breaker->recordFailure($provider);

                throw new ProviderRequestException($provider, "Client error {$status}");
            }

            $this->breaker->recordSuccess($provider);

            return $response->json() ?? [];
        }
    }

    /**
     * Backoff duration for a retry: Retry-After header if numeric, else
     * exponential (1s, 2s, 4s, …) capped at the configured maximum, plus jitter.
     */
    private function backoffSeconds(int $attempt, ?string $retryAfter): float
    {
        $max = (float) config('sync.http.max_backoff', 30);

        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return min((float) $retryAfter, $max);
        }

        $base = min(2 ** ($attempt - 1), $max);
        $jitter = mt_rand(0, 100) / 1000; // up to 100ms

        return min($base + $jitter, $max);
    }
}
