<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryProcurementSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventoryItemId' => [
                'required',
                'integer',
                'exists:ingredients,id',
                Rule::unique('inventory_procurement_settings', 'inventory_item_id'),
            ],
            'preferredSupplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'minimumOrderQty' => ['nullable', 'numeric', 'gte:0'],
            'reorderQty' => ['nullable', 'numeric', 'gte:0'],
            'leadTimeDays' => ['nullable', 'integer', 'gte:0'],
            'lastPurchasePrice' => ['nullable', 'numeric', 'gte:0'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
