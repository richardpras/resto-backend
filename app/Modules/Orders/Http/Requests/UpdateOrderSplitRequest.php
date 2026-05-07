<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'splitType' => ['sometimes', 'in:by_item,by_person,mixed'],
            'label' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:open,partial,paid'],
            'expectedUpdatedAt' => ['sometimes', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
            'items' => ['sometimes', 'array'],
            'items.*.orderItemId' => ['required_with:items', 'integer', 'exists:order_items,id'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.amount' => ['required_with:items', 'numeric', 'gt:0'],
        ];
    }
}
