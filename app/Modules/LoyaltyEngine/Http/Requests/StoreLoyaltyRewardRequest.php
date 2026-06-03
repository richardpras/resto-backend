<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoyaltyRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'pointsCost' => ['required', 'integer', 'min:1'],
            'isActive' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
