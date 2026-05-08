<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterHardwareDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'deviceKey' => ['required', 'string', 'max:120'],
            'displayLabel' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
