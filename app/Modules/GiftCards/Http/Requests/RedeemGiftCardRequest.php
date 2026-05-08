<?php

namespace App\Modules\GiftCards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemGiftCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'code' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'referenceType' => ['nullable', 'string', 'max:64'],
            'referenceId' => ['nullable', 'string', 'max:64'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
