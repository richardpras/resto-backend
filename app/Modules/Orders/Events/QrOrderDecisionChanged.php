<?php

namespace App\Modules\Orders\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class QrOrderDecisionChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $requestId,
        private readonly string $status,
        private readonly ?int $orderId = null,
        private readonly ?string $reason = null,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'qr.order.decision.changed';
    }

    protected function aggregateType(): string
    {
        return 'qr_order_request';
    }

    protected function aggregateId(): string
    {
        return (string) $this->requestId;
    }

    protected function channelSuffix(): string
    {
        return 'qr-orders';
    }

    protected function data(): array
    {
        return [
            'request_id' => $this->requestId,
            'status' => $this->status,
            'order_id' => $this->orderId,
            'reason' => $this->reason,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
