<?php

namespace App\Http\Requests\Api;

use App\Enums\ProviderType;
use App\Enums\SyncStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SyncHistoryRequest extends FormRequest
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
            'provider' => ['sometimes', new Enum(ProviderType::class)],
            'status' => ['sometimes', new Enum(SyncStatus::class)],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 20);
    }
}
