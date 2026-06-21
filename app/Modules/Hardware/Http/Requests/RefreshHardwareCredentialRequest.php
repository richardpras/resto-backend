<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RefreshHardwareCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refreshToken' => ['required', 'string', 'min:32', 'max:128'],
        ];
    }
}
