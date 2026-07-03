<?php

namespace App\Sync\Exceptions;

use App\Enums\ProviderType;
use RuntimeException;
use Throwable;

/**
 * Thrown when a provider request ultimately fails (after request-level retries).
 * Retriable at the job level so the sync is re-attempted with backoff.
 */
class ProviderRequestException extends RuntimeException
{
    public function __construct(
        public readonly ProviderType $provider,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
