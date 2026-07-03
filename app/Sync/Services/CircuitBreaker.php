<?php

namespace App\Sync\Services;

use App\Enums\ProviderType;
use App\Sync\Exceptions\CircuitBreakerOpenException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Per-provider circuit breaker (SPEC §7.3). Counts consecutive failed requests;
 * once the configured threshold is reached the breaker opens, the run is aborted,
 * and the provider is skipped for a cooldown window unless a sync is forced.
 */
class CircuitBreaker
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * Abort early if the breaker is currently open for this provider.
     *
     * @throws CircuitBreakerOpenException
     */
    public function guard(ProviderType $provider): void
    {
        if ($this->isOpen($provider)) {
            throw new CircuitBreakerOpenException($provider, $this->consecutiveFailures($provider));
        }
    }

    public function isOpen(ProviderType $provider): bool
    {
        return (bool) $this->cache->get($this->openKey($provider), false);
    }

    public function consecutiveFailures(ProviderType $provider): int
    {
        return (int) $this->cache->get($this->failureKey($provider), 0);
    }

    /**
     * Record a successful request — resets the breaker to closed.
     */
    public function recordSuccess(ProviderType $provider): void
    {
        $this->cache->forget($this->failureKey($provider));
        $this->cache->forget($this->openKey($provider));
    }

    /**
     * Record a failed request. Trips the breaker (and throws) once the
     * consecutive-failure threshold is reached.
     *
     * @throws CircuitBreakerOpenException
     */
    public function recordFailure(ProviderType $provider): void
    {
        $failures = $this->consecutiveFailures($provider) + 1;
        $this->cache->put($this->failureKey($provider), $failures, now()->addMinutes(10));

        if ($failures >= $this->threshold()) {
            $this->cache->put(
                $this->openKey($provider),
                true,
                now()->addSeconds((int) config('sync.circuit_breaker.cooldown_seconds', 120)),
            );

            throw new CircuitBreakerOpenException($provider, $failures);
        }
    }

    /**
     * Clear breaker state (used when forcing a sync or at run start).
     */
    public function reset(ProviderType $provider): void
    {
        $this->cache->forget($this->failureKey($provider));
        $this->cache->forget($this->openKey($provider));
    }

    private function threshold(): int
    {
        return (int) config('sync.circuit_breaker.failure_threshold', 5);
    }

    private function failureKey(ProviderType $provider): string
    {
        return "sync:breaker:failures:{$provider->value}";
    }

    private function openKey(ProviderType $provider): string
    {
        return "sync:breaker:open:{$provider->value}";
    }
}
