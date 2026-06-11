<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionStationRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:64'],
            'displayOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['sometimes', 'boolean'],
            'kdsEnabled' => ['sometimes', 'boolean'],
            'printEnabled' => ['sometimes', 'boolean'],
        ];
    }
}
