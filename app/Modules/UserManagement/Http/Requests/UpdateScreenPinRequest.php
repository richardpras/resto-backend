<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScreenPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'current_pin' => $this->input('current_pin') ?? $this->input('currentPin'),
        ]);
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
            'current_pin' => ['nullable', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
        ];
    }
}
