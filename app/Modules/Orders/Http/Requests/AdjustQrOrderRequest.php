<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustQrOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.menuItemId' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.unitPrice' => ['nullable', 'numeric', 'gte:0'],
            'adjustments' => ['sometimes', 'array'],
            'adjustments.*.type' => ['required_with:adjustments', 'string', 'max:64'],
            'adjustments.*.name' => ['nullable', 'string', 'max:255'],
            'adjustments.*.reason' => ['nullable', 'string', 'max:255'],
            'adjustments.*.from' => ['nullable', 'string', 'max:255'],
            'adjustments.*.to' => ['nullable', 'string', 'max:255'],
            'promo' => ['nullable', 'array'],
            'promo.promoId' => ['nullable', 'string', 'max:120'],
            'promo.promoName' => ['nullable', 'string', 'max:255'],
            'promoDiscount' => ['nullable', 'numeric', 'gte:0'],
            'voucher' => ['nullable', 'array'],
            'voucher.memberVoucherId' => ['nullable', 'integer', 'min:1'],
            'voucher.voucherName' => ['nullable', 'string', 'max:255'],
            'voucherDiscount' => ['nullable', 'numeric', 'gte:0'],
            'loyalty' => ['nullable', 'array'],
            'loyalty.points' => ['nullable', 'integer', 'min:0'],
            'loyaltyDiscount' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
