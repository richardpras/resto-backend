<?php

namespace App\Modules\Orders\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class OrderLifecycleChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $orderId,
        private readonly string $status,
        private readonly string $paymentStatus,
        private readonly ?string $kitchenStatus,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'order.lifecycle.changed';
    }

    protected function aggregateType(): string
    {
        return 'order';
    }

    protected function aggregateId(): string
    {
        return (string) $this->orderId;
    }

    protected function channelSuffix(): string
    {
        return 'orders';
    }

    protected function data(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'payment_status' => $this->paymentStatus,
            'kitchen_status' => $this->kitchenStatus,
            'meta' => $this->meta(
                $this->sequence,
                $this->aggregateUpdatedAtIso,
                'order:'.$this->orderId.':'.$this->status.':'.$this->paymentStatus
            ),
        ];
    }

    public function broadcastWith(): array
    {
        $payload = parent::broadcastWith();
        $payload['meta'] = $payload['data']['meta'];

        return $payload;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getOrderStatus(): string
    {
        return $this->status;
    }

    public function getPaymentStatus(): string
    {
        return $this->paymentStatus;
    }
}
