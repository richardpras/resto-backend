<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchaseOrderId' => ['required', 'integer', 'exists:purchase_orders,id'],
            'warehouseId' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destinationWarehouseId' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'supplierDeliveryNo' => ['nullable', 'string', 'max:100'],
            'supplierDeliveryDate' => ['nullable', 'date'],
            'vehicleNo' => ['nullable', 'string', 'max:50'],
            'driverName' => ['nullable', 'string', 'max:100'],
            'receivedBy' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventoryItemId' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.receivedQty' => ['required', 'numeric', 'gt:0'],
            'items.*.unitCost' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
