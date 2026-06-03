<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyRewardRedemptionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    LoyaltyRewardRedemption::STATUS_FULFILLED,
                    LoyaltyRewardRedemption::STATUS_CANCELLED,
                ]),
            ],
        ];
    }
}
