<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Enums\ProviderType;
use Database\Factories\SyncAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property AlertType $alert_type
 * @property string $severity
 * @property ProviderType|null $provider_type
 * @property array<string, mixed>|null $details
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['alert_type', 'severity', 'provider_type', 'details', 'notified_at'])]
class SyncAlert extends Model
{
    /** @use HasFactory<SyncAlertFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alert_type' => AlertType::class,
            'provider_type' => ProviderType::class,
            'details' => 'array',
            'notified_at' => 'datetime',
        ];
    }
}
