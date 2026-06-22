<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteOrderRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
