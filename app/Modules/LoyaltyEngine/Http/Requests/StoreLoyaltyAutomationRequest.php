<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyAutomationRequest extends FormRequest
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
            'triggerType' => ['required', 'string', Rule::in(LoyaltyAutomation::TRIGGER_TYPES)],
            'condition' => ['nullable', 'array'],
            'actionType' => ['required', 'string', Rule::in(LoyaltyAutomation::ACTION_TYPES)],
            'actionConfig' => ['nullable', 'array'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
