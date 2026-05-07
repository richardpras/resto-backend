<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcilePaymentTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transactionIds' => ['nullable', 'array'],
            'transactionIds.*' => ['integer', 'exists:payment_transactions,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
