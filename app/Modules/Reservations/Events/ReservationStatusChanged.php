<?php

namespace App\Modules\Reservations\Events;

use App\Events\Realtime\OutletRealtimeEvent;
use App\Modules\Reservations\Support\ReservationRealtimeSnapshot;

class ReservationStatusChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly ReservationRealtimeSnapshot $snapshot,
        private readonly string $fromStatus,
        private readonly string $toStatus,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'reservation.status.changed';
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
            ...$this->snapshot->toPayload(extra: [
                'from_status' => $this->fromStatus,
                'fromStatus' => $this->fromStatus,
                'to_status' => $this->toStatus,
                'toStatus' => $this->toStatus,
            ]),
            'meta' => $this->meta(
                $this->snapshot->sequence(),
                $this->snapshot->updatedAtIso,
                'reservation:'.$this->snapshot->id.':status:'.$this->toStatus,
            ),
        ];
    }
}
