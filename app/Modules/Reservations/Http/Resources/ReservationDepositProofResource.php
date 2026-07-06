<?php

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Reservations\Domain\ReservationDepositProof */
class ReservationDepositProofResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reservationId' => (int) $this->reservation_id,
            'originalFilename' => (string) $this->original_filename,
            'uploadedAt' => $this->uploaded_at?->toISOString(),
            'status' => (string) $this->status,
        ];
    }
}
