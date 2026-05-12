<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordRecoverySettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settlementKind' => ['nullable', 'string', 'max:64'],
            'partialRefundAmount' => ['nullable', 'numeric', 'min:0'],
            'storeCreditAmount' => ['nullable', 'numeric', 'min:0'],
            'giftCardAmount' => ['nullable', 'numeric', 'min:0'],
            'replacedByOrderItemId' => ['nullable', 'integer', 'min:1'],
            'loyaltyPointsAdjustment' => ['nullable', 'integer'],
            'idempotencyKey' => ['required', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
