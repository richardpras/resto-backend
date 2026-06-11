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
            'productionStationId' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('production_stations', 'id')->where(function ($query): void {
                    $outletId = $this->input('outletId');
                    if (is_numeric($outletId) && (int) $outletId > 0) {
                        $query->where('outlet_id', (int) $outletId);
                    }
                }),
            ],
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
