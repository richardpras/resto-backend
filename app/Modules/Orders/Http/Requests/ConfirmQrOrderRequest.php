<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmQrOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'string', 'in:confirm_only,pay_and_confirm'],
            'payments' => ['sometimes', 'array'],
            'payments.*.method' => ['required_with:payments', 'string', 'max:64'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'gt:0'],
            'payments.*.status' => ['sometimes', 'string', 'max:32'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
