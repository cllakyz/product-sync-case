<?php

namespace App\Sync\Services;

use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use App\Models\DeadLetterJob;
use Throwable;

/**
 * Writes exhausted jobs to the dead-letter queue (SPEC §8). Payloads are kept
 * minimal and sanitized — never any credentials — and the exception trace is
 * truncated before persistence.
 */
class DeadLetterService
{
    public function __construct(private readonly AlertManager $alerts) {}

    /**
     * Record a dead-lettered job and re-evaluate DLQ depth alerting.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $jobType,
        ?ProviderType $provider,
        array $payload,
        Throwable $exception,
        int $attempts,
    ): DeadLetterJob {
        $deadLetter = DeadLetterJob::create([
            'job_type' => class_basename($jobType),
            'provider_type' => $provider,
            'payload' => $this->sanitize($payload),
            'exception' => $this->formatException($exception),
            'retry_count' => $attempts,
            'status' => DeadLetterStatus::Pending,
            'failed_at' => now(),
        ]);

        $this->alerts->evaluateDlqDepth();

        return $deadLetter;
    }

    /**
     * Strip anything secret-looking from a payload before storage (defence in
     * depth — the sync payload is already just provider + options).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        $blocked = ['password', 'token', 'authorization', 'secret', 'api_key'];

        return collect($payload)
            ->reject(fn ($value, $key): bool => in_array(strtolower((string) $key), $blocked, true))
            ->all();
    }

    private function formatException(Throwable $exception): string
    {
        $trace = collect(explode("\n", $exception->getTraceAsString()))
            ->take(15)
            ->implode("\n");

        return sprintf(
            "%s: %s\n%s",
            $exception::class,
            $exception->getMessage(),
            $trace,
        );
    }
}
