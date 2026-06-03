<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyProgramRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruleType' => ['nullable', 'string', Rule::in(LoyaltyProgramRule::RULE_TYPES)],
            'config' => ['required', 'array'],
        ];
    }
}
