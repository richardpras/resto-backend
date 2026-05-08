<?php

namespace App\Events\Hardware;

use App\Events\Realtime\OutletRealtimeEvent;

class SpoolRecovered extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $deviceId,
        private readonly int $recoveredCount,
        private readonly ?int $resumeMarker,
    ) {
        parent::__construct($outletId);
    }

    protected function eventName(): string { return 'spool.recovered'; }
    protected function aggregateType(): string { return 'hardware_bridge_device'; }
    protected function aggregateId(): string { return (string) $this->deviceId; }
    protected function channelSuffix(): string { return 'hardware'; }
    protected function data(): array
    {
        return [
            'device_id' => $this->deviceId,
            'recovered_count' => $this->recoveredCount,
            'resume_marker' => $this->resumeMarker,
        ];
    }
}
