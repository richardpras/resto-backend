<?php

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:10'],
            'price' => ['required', 'numeric', 'min:0'],
            'available' => ['sometimes', 'boolean'],
            'recipes' => ['sometimes', 'array'],
            'recipes.*.inventoryItemId' => [
                'required_with:recipes',
                'integer',
                Rule::exists('ingredients', 'id')->where(static fn ($query) => $query->where('type', 'ingredient')),
            ],
            'recipes.*.quantity' => ['required_with:recipes', 'numeric', 'gt:0'],
        ];
    }
}
