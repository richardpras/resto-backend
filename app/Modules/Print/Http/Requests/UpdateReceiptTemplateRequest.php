<?php

namespace App\Modules\Print\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReceiptTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'thermalWidthChars' => ['sometimes', 'integer', 'min:20', 'max:96'],
            'printerProfileId' => ['nullable', 'integer', 'exists:printer_profiles,id'],
            'sections' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'isActive' => ['sometimes', 'boolean'],
            'isDefaultFallback' => ['sometimes', 'boolean'],
        ];
    }
}
