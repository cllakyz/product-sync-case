<?php

namespace App\Http\Requests\Api;

use App\Enums\DeadLetterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class FailedJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', new Enum(DeadLetterStatus::class)],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 20);
    }

    public function statusFilter(): ?DeadLetterStatus
    {
        $value = $this->input('status');

        return $value !== null ? DeadLetterStatus::from($value) : null;
    }
}
