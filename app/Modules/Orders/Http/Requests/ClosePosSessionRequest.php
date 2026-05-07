<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClosePosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'closingCash' => ['required', 'numeric', 'min:0'],
            'closedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
