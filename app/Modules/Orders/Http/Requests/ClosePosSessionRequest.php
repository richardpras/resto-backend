<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClosePosSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('actualCash') && $this->has('closingCash')) {
            $this->merge([
                'actualCash' => $this->input('closingCash'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'actualCash' => ['required_without:closingCash', 'nullable', 'numeric', 'min:0'],
            'closingCash' => ['required_without:actualCash', 'nullable', 'numeric', 'min:0'],
            'closedAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
