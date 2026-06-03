<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $programId = $this->route('loyaltyProgram');

        return [
            'outletId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('loyalty_programs', 'code')->ignore($programId)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'string', Rule::in([
                LoyaltyProgram::TYPE_SPEND_BASED,
                LoyaltyProgram::TYPE_PERIOD_SPENDING,
                LoyaltyProgram::TYPE_VISIT_BASED,
                LoyaltyProgram::TYPE_MANUAL,
                LoyaltyProgram::TYPE_PERCENTAGE_REWARD,
            ])],
            'effectiveFrom' => ['sometimes', 'nullable', 'date'],
            'effectiveUntil' => ['sometimes', 'nullable', 'date'],
            'expiryEnabled' => ['sometimes', 'boolean'],
            'expiryDays' => ['sometimes', 'nullable', 'integer', 'min:1', 'required_if:expiryEnabled,true'],
        ];
    }
}
