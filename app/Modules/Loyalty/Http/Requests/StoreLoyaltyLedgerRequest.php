<?php

namespace App\Modules\Loyalty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoyaltyLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'pointsDelta' => ['required', 'integer', 'min:1'],
            'spendAmount' => ['nullable', 'numeric', 'min:0'],
            'visitIncrement' => ['nullable', 'integer', 'min:0'],
            'clientOccurredAt' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
