<?php

namespace App\Modules\Kitchen\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class KitchenTicketTransitioned extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $ticketId,
        private readonly int $orderId,
        private readonly string $status,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
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
        return (string) $this->ticketId;
    }

    protected function channelSuffix(): string
    {
        return 'kitchen';
    }

    protected function data(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'order_id' => $this->orderId,
            'status' => $this->status,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
