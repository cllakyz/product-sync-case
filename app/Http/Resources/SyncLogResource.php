<?php

namespace App\Http\Resources;

use App\Models\SyncLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncLog
 */
class SyncLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider_type' => $this->provider_type->value,
            'trigger_type' => $this->trigger_type->value,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'stats' => [
                'added' => $this->products_added,
                'updated' => $this->products_updated,
                'deleted' => $this->products_deleted,
                'unchanged' => $this->products_unchanged,
                'failed' => $this->products_failed,
            ],
            'api_requests' => $this->api_requests,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
