<?php

namespace App\Modules\Reservations\Events;

use App\Events\Realtime\OutletRealtimeEvent;
use App\Modules\Reservations\Support\ReservationRealtimeSnapshot;

class ReservationTableAllocated extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly ReservationRealtimeSnapshot $snapshot,
        private readonly int $tableId,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'reservation.table.allocated';
    }

    protected function aggregateType(): string
    {
        return 'reservation';
    }

    protected function aggregateId(): string
    {
        return (string) $this->snapshot->id;
    }

    protected function channelSuffix(): string
    {
        return 'reservations';
    }

    protected function data(): array
    {
        return [
            ...$this->snapshot->toPayload(tableId: $this->tableId),
            'meta' => $this->meta(
                $this->snapshot->sequence(),
                $this->snapshot->updatedAtIso,
                'reservation:'.$this->snapshot->id.':allocated:'.$this->tableId,
            ),
        ];
    }
}
