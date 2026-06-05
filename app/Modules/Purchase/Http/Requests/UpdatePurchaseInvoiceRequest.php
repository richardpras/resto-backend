<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplierInvoiceNo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date' => ['sometimes', 'date'],
            'dueDate' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tax' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'taxPercentage' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'lte:100'],
            'discountAmount' => ['sometimes', 'nullable', 'numeric', 'gte:0'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.inventoryItemId' => ['required_with:items', 'integer', 'exists:ingredients,id'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.invoicedQty' => ['nullable', 'numeric', 'gt:0'],
            'items.*.unitCost' => ['nullable', 'numeric', 'gte:0'],
            'status' => ['sometimes', 'string'],
        ];
    }
}
