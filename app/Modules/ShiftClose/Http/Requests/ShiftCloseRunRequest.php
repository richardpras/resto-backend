<?php

namespace App\Modules\ShiftClose\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShiftCloseRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['required', 'integer', 'min:1'],
            'confirm' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
            'actualCash' => ['nullable', 'numeric', 'min:0'],
            'posSessionId' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('confirm')) {
            $this->merge(['confirm' => false]);
        }
        if (! $this->has('force')) {
            $this->merge(['force' => false]);
        }
    }
}
