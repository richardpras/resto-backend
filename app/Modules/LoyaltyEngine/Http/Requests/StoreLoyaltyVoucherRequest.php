<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyVoucherRequest extends FormRequest
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
            'voucherType' => ['nullable', 'string', Rule::in(LoyaltyVoucher::TYPES)],
            'valueType' => ['required', 'string', Rule::in(LoyaltyVoucher::VALUE_TYPES)],
            'value' => ['nullable', 'numeric', 'min:0'],
            'minimumSpend' => ['nullable', 'numeric', 'min:0'],
            'validFrom' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
