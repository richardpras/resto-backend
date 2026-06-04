<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyAutomationRequest extends FormRequest
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
            'triggerType' => ['sometimes', 'string', Rule::in(LoyaltyAutomation::TRIGGER_TYPES)],
            'condition' => ['nullable', 'array'],
            'actionType' => ['sometimes', 'string', Rule::in(LoyaltyAutomation::ACTION_TYPES)],
            'actionConfig' => ['nullable', 'array'],
        ];
    }
}
