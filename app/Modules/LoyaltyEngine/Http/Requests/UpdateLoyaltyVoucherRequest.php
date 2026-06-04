<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyVoucherRequest extends FormRequest
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
            'voucherType' => ['sometimes', 'string', Rule::in(LoyaltyVoucher::TYPES)],
            'valueType' => ['sometimes', 'string', Rule::in(LoyaltyVoucher::VALUE_TYPES)],
            'value' => ['nullable', 'numeric', 'min:0'],
            'minimumSpend' => ['nullable', 'numeric', 'min:0'],
            'validFrom' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date'],
        ];
    }
}
