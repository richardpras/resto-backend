<?php

namespace App\Modules\Loyalty\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class MembershipTierChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $customerId,
        private readonly ?int $previousTierId,
        private readonly ?int $currentTierId,
        private readonly ?string $currentTierName,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'membership.tier.changed';
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
            'previous_tier_id' => $this->previousTierId,
            'current_tier_id' => $this->currentTierId,
            'current_tier_name' => $this->currentTierName,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
