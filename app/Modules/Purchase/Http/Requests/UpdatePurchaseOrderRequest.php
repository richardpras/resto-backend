<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
            'supplierId' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'purchaseRequestId' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'partial', 'completed'])],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.inventoryItemId' => ['required_with:items', 'integer', 'exists:ingredients,id'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.price' => ['required_with:items', 'numeric', 'gte:0'],
            'items.*.prItemId' => ['nullable', 'integer', 'exists:purchase_request_items,id'],
            'items.*.requestedQty' => ['nullable', 'numeric', 'gte:0'],
            'items.*.isFromPr' => ['nullable', 'boolean'],
        ];
    }
}
