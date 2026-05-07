<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenPosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'openingCash' => ['required', 'numeric', 'min:0'],
            'openedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
