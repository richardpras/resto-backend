<?php

namespace App\Modules\GiftCards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueGiftCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'instrumentType' => ['required', 'in:gift_card,store_credit'],
            'code' => ['required', 'string', 'max:120'],
            'initialAmount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expiresAt' => ['nullable', 'date'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'meta' => ['nullable', 'array'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'paymentReference' => ['nullable', 'string', 'max:120'],
            'cashReceivedAmount' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
