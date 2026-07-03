<?php

namespace App\Sync\Exceptions;

use App\Enums\ProviderType;
use RuntimeException;

/**
 * Thrown when a provider's circuit breaker has tripped after too many
 * consecutive request failures. The current sync run ends as "partial".
 */
class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(public readonly ProviderType $provider, int $failures)
    {
        parent::__construct(
            "Circuit breaker open for provider [{$provider->value}] after {$failures} consecutive failures.",
        );
    }
}
