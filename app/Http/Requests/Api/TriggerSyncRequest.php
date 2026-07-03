<?php

namespace App\Http\Requests\Api;

use App\Enums\ProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class TriggerSyncRequest extends FormRequest
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
            'provider' => ['nullable', new Enum(ProviderType::class)],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function providerType(): ?ProviderType
    {
        $value = $this->input('provider');

        return $value !== null ? ProviderType::from($value) : null;
    }

    public function forced(): bool
    {
        return $this->boolean('force');
    }
}
