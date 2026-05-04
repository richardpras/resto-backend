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
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('accounts', 'code')->ignore($account)],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:asset,liability,equity,revenue,expense'],
            'subtype' => ['sometimes', 'nullable', 'string', 'max:64'],
            'parentId' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
