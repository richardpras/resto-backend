<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouseId' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'supplierDeliveryNo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplierDeliveryDate' => ['sometimes', 'nullable', 'date'],
            'vehicleNo' => ['sometimes', 'nullable', 'string', 'max:50'],
            'driverName' => ['sometimes', 'nullable', 'string', 'max:100'],
            'receivedBy' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.inventoryItemId' => ['required_with:items', 'integer', 'exists:ingredients,id'],
            'items.*.receivedQty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unitCost' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
