<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyProgramRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruleType' => ['sometimes', 'string', Rule::in(LoyaltyProgramRule::RULE_TYPES)],
            'config' => ['sometimes', 'array'],
        ];
    }
}
