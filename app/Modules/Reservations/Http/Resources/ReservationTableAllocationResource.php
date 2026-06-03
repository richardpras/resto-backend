<?php

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Reservations\Domain\ReservationTableAllocation */
class ReservationTableAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $table = $this->relationLoaded('table') ? $this->table : null;

        return [
            'id' => (int) $this->id,
            'reservationId' => (int) $this->reservation_id,
            'tableId' => (int) $this->table_id,
            'tableName' => $table !== null ? (string) $table->name : null,
            'tableCode' => $table?->code !== null ? (string) $table->code : null,
            'allocatedAt' => $this->allocated_at?->toISOString(),
            'allocatedByUserId' => $this->allocated_by_user_id !== null ? (int) $this->allocated_by_user_id : null,
        ];
    }
}
