<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64', 'unique:loyalty_programs,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in([
                LoyaltyProgram::TYPE_SPEND_BASED,
                LoyaltyProgram::TYPE_PERIOD_SPENDING,
                LoyaltyProgram::TYPE_VISIT_BASED,
                LoyaltyProgram::TYPE_MANUAL,
                LoyaltyProgram::TYPE_PERCENTAGE_REWARD,
            ])],
            'isActive' => ['nullable', 'boolean'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveUntil' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'expiryEnabled' => ['nullable', 'boolean'],
            'expiryDays' => ['nullable', 'integer', 'min:1', 'required_if:expiryEnabled,true'],
            'ruleConfig' => ['nullable', 'array'],
        ];
    }
}
