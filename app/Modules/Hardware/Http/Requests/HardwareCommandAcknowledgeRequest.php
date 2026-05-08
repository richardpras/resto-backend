<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HardwareCommandAcknowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ackPayload' => ['nullable', 'array'],
            'nackPayload' => ['nullable', 'array'],
            'errorCode' => ['nullable', 'string', 'max:80'],
            'errorMessage' => ['nullable', 'string', 'max:512'],
        ];
    }
}
