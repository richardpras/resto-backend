<?php

namespace App\Events\Hardware;

use App\Events\Realtime\OutletRealtimeEvent;

class HardwareCommandAcknowledged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $commandId,
        private readonly string $commandType,
        private readonly string $status,
    ) {
        parent::__construct($outletId);
    }

    protected function eventName(): string { return 'hardware.command.acknowledged'; }
    protected function aggregateType(): string { return 'hardware_command'; }
    protected function aggregateId(): string { return (string) $this->commandId; }
    protected function channelSuffix(): string { return 'hardware'; }
    protected function data(): array
    {
        return [
            'command_id' => $this->commandId,
            'command_type' => $this->commandType,
            'status' => $this->status,
        ];
    }
}
