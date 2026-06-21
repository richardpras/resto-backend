<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemHardwarePairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:4', 'max:12'],
            'deviceKey' => ['required', 'string', 'max:120'],
            'displayLabel' => ['nullable', 'string', 'max:255'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
            'capabilities' => ['nullable', 'array'],
        ];
    }
}
