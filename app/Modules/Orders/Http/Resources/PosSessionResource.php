<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Orders\Domain\PosSession */
class PosSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'openedByUserId' => (int) $this->opened_by_user_id,
            'closedByUserId' => $this->closed_by_user_id !== null ? (int) $this->closed_by_user_id : null,
            'status' => (string) $this->status,
            'openingCash' => (float) $this->opening_cash,
            'closingCash' => $this->closing_cash !== null ? (float) $this->closing_cash : null,
            'expectedCash' => $this->expected_cash !== null ? (float) $this->expected_cash : null,
            'actualCash' => $this->actual_cash !== null ? (float) $this->actual_cash : null,
            'cashVariance' => $this->cash_variance !== null ? (float) $this->cash_variance : null,
            'openedAt' => $this->opened_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
