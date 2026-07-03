<?php

namespace App\Models;

use App\Enums\DeadLetterStatus;
use App\Enums\ProviderType;
use Database\Factories\DeadLetterJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $job_type
 * @property ProviderType|null $provider_type
 * @property array<string, mixed> $payload
 * @property string $exception
 * @property int $retry_count
 * @property DeadLetterStatus $status
 * @property Carbon $failed_at
 * @property Carbon|null $last_retried_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'job_type', 'provider_type', 'payload', 'exception', 'retry_count',
    'status', 'failed_at', 'last_retried_at', 'resolved_at',
])]
class DeadLetterJob extends Model
{
    /** @use HasFactory<DeadLetterJobFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_type' => ProviderType::class,
            'payload' => 'array',
            'status' => DeadLetterStatus::class,
            'failed_at' => 'datetime',
            'last_retried_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Whether this dead-lettered job is eligible for a manual retry.
     */
    public function isRetriable(): bool
    {
        return in_array($this->status, [DeadLetterStatus::Pending, DeadLetterStatus::Discarded], true);
    }
}
