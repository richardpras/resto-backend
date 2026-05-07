<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:64', Rule::unique('outlets', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'manager' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'invoicePrefix' => ['nullable', 'string', 'max:64'],
            'orderPrefix' => ['nullable', 'string', 'max:64'],
            'outlet_id' => ['sometimes', 'integer', 'min:1'],
            'outletId' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
