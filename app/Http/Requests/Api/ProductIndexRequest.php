<?php

namespace App\Http\Requests\Api;

use App\Enums\ProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ProductIndexRequest extends FormRequest
{
    /**
     * Columns clients are allowed to sort by (allow-list guards against SQL
     * injection via the sort parameter — SPEC §11.2).
     */
    public const SORTABLE = ['name', 'price', 'stock', 'last_synced_at'];

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
            'search' => ['sometimes', 'string', 'max:100'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0', 'gte:min_price'],
            'in_stock' => ['sometimes', 'boolean'],
            'include_deleted' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(self::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', 20);
    }

    public function sortColumn(): string
    {
        return $this->input('sort', 'last_synced_at');
    }

    public function sortDirection(): string
    {
        return $this->input('direction', 'desc');
    }
}
