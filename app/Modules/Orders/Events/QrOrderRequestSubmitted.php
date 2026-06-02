<?php

namespace App\Modules\Orders\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class QrOrderRequestSubmitted extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $requestId,
        private readonly string $requestCode,
        private readonly int $tableId,
        private readonly ?string $customerName,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'qr.order.request.submitted';
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
            'request_code' => $this->requestCode,
            'table_id' => $this->tableId,
            'customer_name' => $this->customerName,
            'status' => 'pending_cashier_confirmation',
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
