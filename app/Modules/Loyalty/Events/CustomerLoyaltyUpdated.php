<?php

namespace App\Modules\Loyalty\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class CustomerLoyaltyUpdated extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $customerId,
        private readonly int $pointsBalance,
        private readonly int $pointsDelta,
        private readonly string $reason,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'customer.loyalty.updated';
    }

    protected function aggregateType(): string
    {
        return 'loyalty_account';
    }

    protected function aggregateId(): string
    {
        return (string) $this->customerId;
    }

    protected function channelSuffix(): string
    {
        return 'loyalty';
    }

    protected function data(): array
    {
        return [
            'customer_id' => $this->customerId,
            'points_balance' => $this->pointsBalance,
            'points_delta' => $this->pointsDelta,
            'reason' => $this->reason,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
