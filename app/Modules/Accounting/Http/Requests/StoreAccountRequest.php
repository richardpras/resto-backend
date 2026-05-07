<?php

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tenantId' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'outletId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'scope' => ['sometimes', 'string', 'in:global,outlet'],
            'code' => ['required', 'string', 'max:32', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subtype' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config' => ['sometimes', 'nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
