<?php

namespace App\Modules\GiftCards\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\GiftCards\Domain\GiftCardIssuance */
class GiftCardIssuanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'instrumentType' => (string) $this->instrument_type,
            'code' => (string) $this->code,
            'issuedAmount' => (float) $this->issued_amount,
            'balanceAmount' => (float) $this->balance_amount,
            'currency' => (string) $this->currency,
            'status' => (string) $this->status,
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'lastRedeemedAt' => $this->last_redeemed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
