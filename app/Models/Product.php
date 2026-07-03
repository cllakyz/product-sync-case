<?php

namespace App\Models;

use App\Enums\ProviderType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ProviderType $provider_type
 * @property string $external_id
 * @property string $name
 * @property string $price
 * @property int|null $stock
 * @property string|null $description
 * @property string $data_hash
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon $last_synced_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'provider_type', 'external_id', 'name', 'price', 'stock',
    'description', 'data_hash', 'raw_payload', 'last_synced_at',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_type' => ProviderType::class,
            'price' => 'decimal:2',
            'stock' => 'integer',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
