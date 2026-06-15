<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddOrderPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', 'max:50'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.paidAt' => ['nullable', 'date'],
            'payments.*.splitBillLabel' => ['nullable', 'string', 'max:100'],
            'payments.*.splitBillGroup' => ['nullable', 'string', 'max:100'],
            'payments.*.orderSplitId' => ['nullable', 'integer', 'exists:order_splits,id'],
            'payments.*.status' => ['nullable', 'in:paid,pending,failed,void'],
            'payments.*.allocations' => ['nullable', 'array', 'min:1'],
            'payments.*.allocations.*.orderItemId' => ['required_with:payments.*.allocations', 'integer', 'exists:order_items,id'],
            'payments.*.allocations.*.qty' => ['required_with:payments.*.allocations', 'numeric', 'gt:0'],
            'payments.*.allocations.*.amount' => ['required_with:payments.*.allocations', 'numeric', 'gt:0'],
            'expectedUpdatedAt' => ['sometimes', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
            'cashAccountCode' => ['nullable', 'string', 'max:50'],
            'revenueAccountCode' => ['nullable', 'string', 'max:50'],
            'qrOrderRequestId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
