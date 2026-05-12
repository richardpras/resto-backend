<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'orderId' => ['required', 'integer', 'exists:orders,id'],
            'orderSplitId' => ['nullable', 'integer', 'exists:order_splits,id'],
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'provider' => ['nullable', 'string', 'max:64'],
            'externalReference' => ['required', 'string', 'max:120'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'paymentMethod' => ['nullable', 'in:qris,cashless,bank_transfer,ewallet'],
            'payloadSnapshot' => ['nullable', 'array'],
            'expiredAt' => ['nullable', 'date'],
        ];
    }
}
