<?php

namespace App\Modules\Print\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignPrinterRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'printerProfileId' => ['required', 'integer', 'exists:printer_profiles,id'],
            'printType' => ['required', 'string', Rule::in(['kitchen', 'receipt'])],
            'routeScope' => ['nullable', 'string', Rule::in(['item', 'category', 'station', 'default'])],
            'itemId' => ['nullable', 'integer', 'exists:menu_items,id'],
            'station' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:120'],
            'sourceCategory' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:999'],
            'isActive' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
