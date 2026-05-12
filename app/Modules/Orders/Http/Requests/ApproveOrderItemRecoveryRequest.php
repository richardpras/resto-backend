<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveOrderItemRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payload' => ['nullable', 'array'],
            'payload.replacedByOrderItemId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
