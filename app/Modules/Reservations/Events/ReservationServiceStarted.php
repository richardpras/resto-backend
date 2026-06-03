<?php

namespace App\Modules\Reservations\Events;

use App\Events\Realtime\OutletRealtimeEvent;
use App\Modules\Reservations\Support\ReservationRealtimeSnapshot;

class ReservationServiceStarted extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly ReservationRealtimeSnapshot $snapshot,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'reservation.service.started';
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
            ...$this->snapshot->toPayload(),
            'meta' => $this->meta(
                $this->snapshot->sequence(),
                $this->snapshot->updatedAtIso,
                'reservation:'.$this->snapshot->id.':service-started',
            ),
        ];
    }
}
