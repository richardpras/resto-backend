<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoyaltyRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'pointsCost' => ['sometimes', 'integer', 'min:1'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
