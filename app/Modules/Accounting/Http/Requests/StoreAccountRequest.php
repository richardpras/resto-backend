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
            'code' => ['required', 'string', 'max:32', 'unique:accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense'],
            'subtype' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
