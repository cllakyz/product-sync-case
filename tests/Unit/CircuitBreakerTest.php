<?php

namespace Tests\Unit;

use App\Enums\ProviderType;
use App\Sync\Exceptions\CircuitBreakerOpenException;
use App\Sync\Services\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(Cache::store());
    }

    public function test_it_opens_after_the_configured_number_of_consecutive_failures()
    {
        config(['sync.circuit_breaker.failure_threshold' => 3]);
        $breaker = $this->breaker();
        $provider = ProviderType::DummyJson;

        $breaker->recordFailure($provider);
        $breaker->recordFailure($provider);
        $this->assertFalse($breaker->isOpen($provider));

        $this->expectException(CircuitBreakerOpenException::class);
        $breaker->recordFailure($provider); // third → trips
    }

    public function test_open_breaker_is_reported_and_guard_throws()
    {
        config(['sync.circuit_breaker.failure_threshold' => 1]);
        $breaker = $this->breaker();
        $provider = ProviderType::DummyJson;

        try {
            $breaker->recordFailure($provider);
        } catch (CircuitBreakerOpenException) {
            // expected
        }

        $this->assertTrue($breaker->isOpen($provider));
        $this->expectException(CircuitBreakerOpenException::class);
        $breaker->guard($provider);
    }

    public function test_success_resets_the_failure_count()
    {
        config(['sync.circuit_breaker.failure_threshold' => 3]);
        $breaker = $this->breaker();
        $provider = ProviderType::DummyJson;

        $breaker->recordFailure($provider);
        $breaker->recordFailure($provider);
        $breaker->recordSuccess($provider);

        $this->assertSame(0, $breaker->consecutiveFailures($provider));
        $this->assertFalse($breaker->isOpen($provider));
    }
}
