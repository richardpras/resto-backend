<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryProcurementSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferredSupplierId' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'minimumOrderQty' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'reorderQty' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'leadTimeDays' => ['sometimes', 'nullable', 'integer', 'gte:0'],
            'lastPurchasePrice' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
