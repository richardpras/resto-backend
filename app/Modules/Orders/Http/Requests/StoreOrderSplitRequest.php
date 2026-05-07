<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'splitType' => ['required', 'in:by_item,by_person,mixed'],
            'label' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'in:open,partial,paid'],
            'expectedUpdatedAt' => ['sometimes', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
            'items' => ['required', 'array'],
            'items.*.orderItemId' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
