<?php

namespace App\Modules\GiftCards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettleGiftCardRedemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'settlementReference' => ['required', 'string', 'max:120'],
            'paymentTransactionId' => ['nullable', 'integer'],
            'settlementStatus' => ['required', 'in:pending,settled,failed,reversed'],
            'redeemSettlementIds' => ['required', 'array', 'min:1'],
            'redeemSettlementIds.*' => ['required', 'integer', 'exists:gift_card_redemption_settlements,id'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
