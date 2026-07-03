<?php

namespace App\Http\Resources;

use App\Models\DeadLetterJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin DeadLetterJob
 */
class DeadLetterJobResource extends JsonResource
{
    /**
     * The exception is truncated here; the full trace stays in the logs
     * (SPEC §11.5 output sanitization).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_type' => $this->job_type,
            'provider_type' => $this->provider_type?->value,
            'status' => $this->status->value,
            'retry_count' => $this->retry_count,
            'exception' => Str::limit($this->exception, 300),
            'failed_at' => $this->failed_at->toIso8601String(),
            'last_retried_at' => $this->last_retried_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
