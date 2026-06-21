<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitHardwarePairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'displayLabel' => ['nullable', 'string', 'max:255'],
        ];
    }
}
