<?php

namespace App\Events\Hardware;

use App\Events\Realtime\OutletRealtimeEvent;

class QueueDegraded extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $deviceId,
        private readonly int $queueDepth,
        private readonly int $deadLetterCount,
    ) {
        parent::__construct($outletId);
    }

    protected function eventName(): string { return 'queue.degraded'; }
    protected function aggregateType(): string { return 'hardware_bridge_device'; }
    protected function aggregateId(): string { return (string) $this->deviceId; }
    protected function channelSuffix(): string { return 'hardware'; }
    protected function data(): array
    {
        return [
            'device_id' => $this->deviceId,
            'queue_depth' => $this->queueDepth,
            'dead_letter_count' => $this->deadLetterCount,
        ];
    }
}
