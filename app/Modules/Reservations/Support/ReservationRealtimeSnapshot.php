<?php

namespace App\Modules\Reservations\Support;

use App\Models\Modules\Reservations\Domain\Reservation;

readonly class ReservationRealtimeSnapshot
{
    /** @param  list<int>  $allocatedTableIds */
    public function __construct(
        public int $id,
        public int $outletId,
        public string $status,
        public int $partySize,
        public ?string $reservationAtIso,
        public array $allocatedTableIds,
        public ?int $linkedOrderId,
        public ?string $updatedAtIso,
    ) {}

    public static function fromModel(Reservation $reservation): self
    {
        $reservation->loadMissing('tableAllocations');

        return new self(
            id: (int) $reservation->id,
            outletId: (int) $reservation->outlet_id,
            status: (string) $reservation->status,
            partySize: (int) $reservation->party_size,
            reservationAtIso: $reservation->reservation_at?->toISOString(),
            allocatedTableIds: $reservation->tableAllocations
                ->pluck('table_id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all(),
            linkedOrderId: $reservation->linked_order_id !== null ? (int) $reservation->linked_order_id : null,
            updatedAtIso: $reservation->updated_at?->toISOString(),
        );
    }

    public function sequence(): int
    {
        if ($this->updatedAtIso !== null) {
            $parsed = strtotime($this->updatedAtIso);

            return $parsed !== false ? $parsed : (int) $this->id;
        }

        return (int) $this->id;
    }

    /** @return array<string, mixed> */
    public function toPayload(?int $tableId = null, array $extra = []): array
    {
        $payload = [
            'reservation_id' => $this->id,
            'reservationId' => $this->id,
            'id' => $this->id,
            'outlet_id' => $this->outletId,
            'outletId' => $this->outletId,
            'status' => $this->status,
            'party_size' => $this->partySize,
            'partySize' => $this->partySize,
            'reservation_at' => $this->reservationAtIso,
            'reservationAt' => $this->reservationAtIso,
            'allocated_table_ids' => $this->allocatedTableIds,
            'allocatedTableIds' => $this->allocatedTableIds,
            'linked_order_id' => $this->linkedOrderId,
            'linkedOrderId' => $this->linkedOrderId,
        ];

        if ($tableId !== null) {
            $payload['table_id'] = $tableId;
            $payload['tableId'] = $tableId;
        }

        return array_merge($payload, $extra);
    }
}
