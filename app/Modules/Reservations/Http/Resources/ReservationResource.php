<?php

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Reservations\Domain\Reservation */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'tableId' => $this->table_id !== null ? (int) $this->table_id : null,
            'reservationCode' => (string) $this->reservation_code,
            'customerName' => (string) $this->customer_name,
            'customerPhone' => $this->customer_phone !== null ? (string) $this->customer_phone : null,
            'memberId' => $this->member_id !== null ? (int) $this->member_id : null,
            'partySize' => (int) $this->party_size,
            'reservationAt' => $this->reservation_at?->toISOString(),
            'confirmedAt' => $this->confirmed_at?->toISOString(),
            'checkedInAt' => $this->checked_in_at?->toISOString(),
            'seatedAt' => $this->seated_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'noShowAt' => $this->no_show_at?->toISOString(),
            'linkedOrderId' => $this->linked_order_id !== null ? (int) $this->linked_order_id : null,
            'serviceStartedAt' => $this->service_started_at?->toISOString(),
            'status' => (string) $this->status,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
