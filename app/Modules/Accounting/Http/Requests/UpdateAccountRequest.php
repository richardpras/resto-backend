<?php

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
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
        $account = $this->route('account');

        return [
            'outletId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'scope' => ['sometimes', 'string', 'in:global,outlet'],
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('accounts', 'code')->ignore($account)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:asset,liability,equity,revenue,expense'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subtype' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config' => ['sometimes', 'nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
