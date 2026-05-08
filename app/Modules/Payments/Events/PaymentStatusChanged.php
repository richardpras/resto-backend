<?php

namespace App\Modules\Payments\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class PaymentStatusChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $transactionId,
        private readonly int $orderId,
        private readonly string $status,
        private readonly ?string $provider,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'payment.status.changed';
    }

    protected function aggregateType(): string
    {
        return 'payment_transaction';
    }

    protected function aggregateId(): string
    {
        return (string) $this->transactionId;
    }

    protected function channelSuffix(): string
    {
        return 'payments';
    }

    protected function data(): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'order_id' => $this->orderId,
            'status' => $this->status,
            'provider' => $this->provider,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
