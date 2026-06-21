<?php

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuCategoryPrinterMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['required', 'integer', 'min:1', Rule::exists('outlets', 'id')],
            'menuCategoryId' => ['required', 'integer', 'min:1', Rule::exists('menu_categories', 'id')],
            'printerProfileId' => ['required', 'integer', 'min:1', Rule::exists('printer_profiles', 'id')],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['sometimes', 'boolean'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
