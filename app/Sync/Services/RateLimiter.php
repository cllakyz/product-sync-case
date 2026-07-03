<?php

namespace App\Sync\Services;

use App\Enums\ProviderType;
use App\Sync\Support\Sleeper;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Client-side throttling (SPEC §7.1): spaces outbound requests so a single
 * provider never exceeds the configured requests-per-second. Delays go through
 * an injected Sleeper so they can be asserted without blocking tests.
 */
class RateLimiter
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Sleeper $sleeper,
    ) {}

    /**
     * Block just long enough (if needed) to honour the per-provider rate limit,
     * then record this request's timestamp.
     */
    public function throttle(ProviderType $provider): void
    {
        $wait = $this->secondsUntilAllowed($provider);

        if ($wait > 0) {
            $this->sleeper->seconds($wait);
        }

        $this->cache->put($this->key($provider), microtime(true), now()->addMinute());
    }

    /**
     * How long the caller must wait before the next request is permitted.
     */
    public function secondsUntilAllowed(ProviderType $provider): float
    {
        $interval = $this->minInterval();
        $last = $this->cache->get($this->key($provider));

        if ($last === null) {
            return 0.0;
        }

        $elapsed = microtime(true) - (float) $last;

        return max(0.0, $interval - $elapsed);
    }

    /**
     * Minimum spacing between requests, in seconds (1 / max_rps).
     */
    private function minInterval(): float
    {
        $maxRps = max(1, (int) config('sync.max_requests_per_second', 5));

        return 1.0 / $maxRps;
    }

    private function key(ProviderType $provider): string
    {
        return "sync:rate:last:{$provider->value}";
    }
}
