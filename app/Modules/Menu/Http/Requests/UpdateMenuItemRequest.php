<?php

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'emoji' => ['sometimes', 'string', 'max:10'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'available' => ['sometimes', 'boolean'],
            'productionStationId' => ['nullable', 'integer', 'min:1', Rule::exists('production_stations', 'id')],
            'recipes' => ['sometimes', 'array'],
            'recipes.*.inventoryItemId' => [
                'required_with:recipes',
                'integer',
                Rule::exists('ingredients', 'id')->where(static fn ($query) => $query->where('type', 'ingredient')),
            ],
            'recipes.*.quantity' => ['required_with:recipes', 'numeric', 'gt:0'],
            'menuItemOutlets' => ['sometimes', 'array'],
            'menuItemOutlets.*.outletId' => ['required_with:menuItemOutlets', 'integer', 'min:1', Rule::exists('outlets', 'id')],
            'menuItemOutlets.*.isActive' => ['sometimes', 'boolean'],
            'menuItemOutlets.*.priceOverride' => ['nullable', 'numeric', 'min:0'],
            'menuItemOutlets.*.nameOverride' => ['nullable', 'string', 'max:255'],
            'menuItemOutlets.*.receiptName' => ['nullable', 'string', 'max:255'],
        ];
    }
}
