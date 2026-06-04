<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyTierRequest extends FormRequest
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
            'qualificationType' => ['sometimes', 'string', Rule::in(LoyaltyTier::QUALIFICATION_TYPES)],
            'qualificationConfig' => ['nullable', 'array'],
            'benefitConfig' => ['nullable', 'array'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
