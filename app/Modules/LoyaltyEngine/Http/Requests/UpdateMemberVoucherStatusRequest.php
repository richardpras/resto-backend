<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberVoucherStatusRequest extends FormRequest
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
                    MemberVoucher::STATUS_CLAIMED,
                    MemberVoucher::STATUS_REDEEMED,
                    MemberVoucher::STATUS_EXPIRED,
                    MemberVoucher::STATUS_CANCELLED,
                ]),
            ],
        ];
    }
}
