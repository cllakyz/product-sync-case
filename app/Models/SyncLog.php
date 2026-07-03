<?php

namespace App\Models;

use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use App\Enums\TriggerType;
use Database\Factories\SyncLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ProviderType $provider_type
 * @property TriggerType $trigger_type
 * @property SyncStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $duration_ms
 * @property int $products_added
 * @property int $products_updated
 * @property int $products_deleted
 * @property int $products_unchanged
 * @property int $products_failed
 * @property int $api_requests
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'provider_type', 'trigger_type', 'status', 'started_at', 'completed_at',
    'duration_ms', 'products_added', 'products_updated', 'products_deleted',
    'products_unchanged', 'products_failed', 'api_requests', 'error_message',
])]
class SyncLog extends Model
{
    /** @use HasFactory<SyncLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_type' => ProviderType::class,
            'trigger_type' => TriggerType::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Runs that currently occupy a provider slot (pending or running).
     *
     * @param  Builder<SyncLog>  $query
     * @return Builder<SyncLog>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', SyncStatus::activeValues());
    }
}
