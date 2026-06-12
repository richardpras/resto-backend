<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:50'],
            'source' => ['required', 'in:pos,qr'],
            'orderType' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:pending,confirmed,cooking,ready,completed,cancelled'],
            'paymentStatus' => ['required', 'in:unpaid,partial,paid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'string', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.emoji' => ['nullable', 'string', 'max:10'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'discountAmount' => ['nullable', 'numeric', 'min:0'],
            'customerName' => ['nullable', 'string', 'max:255'],
            'customerPhone' => ['nullable', 'string', 'max:50'],
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'tableId' => ['nullable', 'integer', 'min:1'],
            'tableNumber' => ['nullable', 'string', 'max:120'],
            'serviceMode' => ['nullable', 'in:dine_in,takeaway'],
            'orderChannel' => ['nullable', 'in:dine_in,takeaway,qr'],
            'posSessionId' => ['nullable', 'integer', 'min:1'],
            'qrOrderRequestId' => ['nullable', 'integer', 'min:1'],
            'createdAt' => ['nullable', 'date'],
            'confirmedAt' => ['nullable', 'date'],
            'splitBill' => ['nullable', 'array'],
            'payments' => ['present', 'array'],
            'payments.*.method' => ['required', 'string', 'max:50'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.paidAt' => ['nullable', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
