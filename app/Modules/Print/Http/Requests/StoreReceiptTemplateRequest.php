<?php

namespace App\Modules\Print\Http\Requests;

use App\Modules\Print\Support\ReceiptDocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReceiptTemplateRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in(ReceiptDocumentKind::values())],
            'code' => ['sometimes', 'string', 'max:64'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:160'],
            'thermalWidthChars' => ['sometimes', 'integer', 'min:20', 'max:96'],
            'printerProfileId' => ['nullable', 'integer', 'exists:printer_profiles,id'],
            'sections' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'isActive' => ['sometimes', 'boolean'],
            'isDefaultFallback' => ['sometimes', 'boolean'],
        ];
    }
}
