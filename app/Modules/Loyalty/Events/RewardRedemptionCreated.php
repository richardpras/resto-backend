<?php

namespace App\Modules\Loyalty\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class RewardRedemptionCreated extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $redemptionId,
        private readonly int $customerId,
        private readonly string $rewardCode,
        private readonly int $pointsCost,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'reward.redemption.created';
    }

    protected function aggregateType(): string
    {
        return 'loyalty_reward_redemption';
    }

    protected function aggregateId(): string
    {
        return (string) $this->redemptionId;
    }

    protected function channelSuffix(): string
    {
        return 'loyalty';
    }

    protected function data(): array
    {
        return [
            'redemption_id' => $this->redemptionId,
            'customer_id' => $this->customerId,
            'reward_code' => $this->rewardCode,
            'points_cost' => $this->pointsCost,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
