<?php

namespace App\Modules\Kitchen\Events;

use App\Events\Realtime\OutletRealtimeEvent;
use App\Modules\Kitchen\Support\KitchenRealtimeSnapshot;

class KitchenTicketTransitioned extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly KitchenRealtimeSnapshot $snapshot,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'kitchen.ticket.transitioned';
    }

    protected function aggregateType(): string
    {
        return 'kitchen_ticket';
    }

    protected function aggregateId(): string
    {
        return (string) $this->snapshot->id;
    }

    protected function channelSuffix(): string
    {
        return 'kitchen';
    }

    protected function data(): array
    {
        return [
            ...$this->snapshot->toPayload(),
            'meta' => $this->meta(
                $this->snapshot->sequence(),
                $this->snapshot->updatedAtIso,
                $this->snapshot->replayKey(),
            ),
        ];
    }
}
