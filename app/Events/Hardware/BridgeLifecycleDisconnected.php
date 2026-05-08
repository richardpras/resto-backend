<?php

namespace App\Events\Hardware;

use App\Events\Realtime\OutletRealtimeEvent;

class BridgeLifecycleDisconnected extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $deviceId,
        private readonly string $deviceKey,
        private readonly string $reason,
    ) {
        parent::__construct($outletId);
    }

    protected function eventName(): string { return 'bridge.disconnected'; }
    protected function aggregateType(): string { return 'hardware_bridge_device'; }
    protected function aggregateId(): string { return (string) $this->deviceId; }
    protected function channelSuffix(): string { return 'hardware'; }
    protected function data(): array
    {
        return [
            'device_id' => $this->deviceId,
            'device_key' => $this->deviceKey,
            'reason' => $this->reason,
        ];
    }
}
