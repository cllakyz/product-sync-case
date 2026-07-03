<?php

namespace Tests\Unit;

use App\Enums\ProviderType;
use App\Sync\Services\RateLimiter;
use App\Sync\Support\Sleeper;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimiterTest extends TestCase
{
    public function test_first_request_is_not_delayed()
    {
        config(['sync.max_requests_per_second' => 5]);
        $limiter = new RateLimiter(Cache::store(), new Sleeper);

        $this->assertSame(0.0, $limiter->secondsUntilAllowed(ProviderType::DummyJson));
    }

    public function test_it_spaces_requests_by_the_minimum_interval()
    {
        config(['sync.max_requests_per_second' => 5]); // 200ms interval

        $recorded = [];
        $sleeper = new class($recorded) extends Sleeper
        {
            /** @param array<int, float> $recorded */
            public function __construct(public array &$recorded) {}

            public function seconds(float $seconds): void
            {
                $this->recorded[] = $seconds;
            }
        };

        $limiter = new RateLimiter(Cache::store(), $sleeper);
        $provider = ProviderType::DummyJson;

        $limiter->throttle($provider); // records timestamp, no wait
        $limiter->throttle($provider); // must wait close to the 200ms interval

        $this->assertNotEmpty($sleeper->recorded);
        $this->assertGreaterThan(0.0, end($sleeper->recorded));
        $this->assertLessThanOrEqual(0.2, end($sleeper->recorded));
    }
}
