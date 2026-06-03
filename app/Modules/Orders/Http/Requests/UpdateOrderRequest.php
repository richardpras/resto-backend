<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['required_with:items', 'string', 'max:100'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.emoji' => ['nullable', 'string', 'max:10'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'subtotal' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'total' => ['sometimes', 'numeric', 'min:0'],
            'discountAmount' => ['sometimes', 'numeric', 'min:0'],
            'customerName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customerPhone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'memberId' => ['sometimes', 'nullable', 'integer', 'exists:members,id'],
            'tableId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expectedUpdatedAt' => ['sometimes', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
            'paymentStatus' => ['prohibited'],
            'kitchenStatus' => ['prohibited'],
        ];
    }
}
