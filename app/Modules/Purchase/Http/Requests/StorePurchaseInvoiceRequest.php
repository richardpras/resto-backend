<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchaseOrderId' => ['required', 'integer', 'exists:purchase_orders,id'],
            'goodsReceiptId' => ['required', 'integer', 'exists:goods_receiving_notes,id'],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplierInvoiceNo' => ['nullable', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'dueDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'tax' => ['nullable', 'numeric', 'gte:0'],
            'taxPercentage' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'discountAmount' => ['nullable', 'numeric', 'gte:0'],
            'items' => ['nullable', 'array'],
            'items.*.inventoryItemId' => ['required_with:items', 'integer', 'exists:ingredients,id'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.invoicedQty' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unitCost' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
