<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'supplierId' => ['required', 'integer', 'exists:suppliers,id'],
            'purchaseRequestId' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'status' => ['required', Rule::in(['draft', 'sent', 'partial', 'completed'])],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventoryItemId' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.price' => ['required', 'numeric', 'gte:0'],
            'items.*.prItemId' => ['nullable', 'integer', 'exists:purchase_request_items,id'],
            'items.*.requestedQty' => ['nullable', 'numeric', 'gte:0'],
            'items.*.isFromPr' => ['nullable', 'boolean'],
        ];
    }
}
