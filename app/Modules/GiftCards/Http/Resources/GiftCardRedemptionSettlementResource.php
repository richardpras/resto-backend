<?php

namespace App\Modules\GiftCards\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement */
class GiftCardRedemptionSettlementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'issuanceId' => (int) $this->issuance_id,
            'ledgerEntryId' => $this->ledger_entry_id !== null ? (int) $this->ledger_entry_id : null,
            'outletId' => (int) $this->outlet_id,
            'idempotencyKey' => (string) $this->idempotency_key,
            'settlementReference' => $this->settlement_reference,
            'paymentTransactionId' => $this->payment_transaction_id,
            'redeemedAmount' => (float) $this->redeemed_amount,
            'status' => (string) $this->status,
            'redeemedAt' => $this->redeemed_at?->toIso8601String(),
            'settledAt' => $this->settled_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
