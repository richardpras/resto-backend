<?php

namespace App\Modules\Loyalty\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger */
class LoyaltyPointsLedgerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'customerId' => (int) $this->loyalty_account_id,
            'outletId' => (int) $this->outlet_id,
            'idempotencyKey' => (string) $this->idempotency_key,
            'transactionType' => (string) $this->transaction_type,
            'pointsDelta' => (int) $this->points_delta,
            'balanceBefore' => (int) $this->balance_before,
            'balanceAfter' => (int) $this->balance_after,
            'spendAmount' => (float) $this->spend_amount,
            'visitIncrement' => (int) $this->visit_increment,
            'clientOccurredAt' => $this->client_occurred_at?->toIso8601String(),
            'appliedAt' => $this->applied_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
