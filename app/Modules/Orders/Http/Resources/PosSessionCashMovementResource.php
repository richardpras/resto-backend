<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Orders\Domain\PosSessionCashMovement */
class PosSessionCashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'posSessionId' => (int) $this->pos_session_id,
            'direction' => (string) $this->direction,
            'amount' => (float) $this->amount,
            'category' => (string) $this->category,
            'notes' => $this->notes,
            'createdByUserId' => $this->created_by_user_id !== null ? (int) $this->created_by_user_id : null,
            'occurredAt' => $this->occurred_at?->toIso8601String(),
            'clientLocalRef' => $this->client_local_ref,
            'journalId' => $this->journal_id !== null ? (int) $this->journal_id : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
