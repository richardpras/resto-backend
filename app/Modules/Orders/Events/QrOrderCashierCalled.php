<?php

namespace App\Modules\Orders\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class QrOrderCashierCalled extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $requestId,
        private readonly string $requestCode,
        private readonly int $tableId,
        private readonly int $callCount,
        private readonly string $calledAtIso,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'qr.order.cashier.called';
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
            'id' => (string) $this->requestId,
            'requestCode' => $this->requestCode,
            'tableId' => $this->tableId,
            'cashierCallCount' => $this->callCount,
            'cashierCalledAt' => $this->calledAtIso,
            'request_id' => $this->requestId,
            'request_code' => $this->requestCode,
            'table_id' => $this->tableId,
            'cashier_call_count' => $this->callCount,
            'cashier_called_at' => $this->calledAtIso,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
