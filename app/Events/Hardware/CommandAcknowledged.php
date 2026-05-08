<?php

namespace App\Events\Hardware;

use App\Events\Realtime\OutletRealtimeEvent;

class CommandAcknowledged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $commandId,
        private readonly string $commandType,
        private readonly string $spoolStatus,
        private readonly int $retryCount,
    ) {
        parent::__construct($outletId);
    }

    protected function eventName(): string { return 'command.acknowledged'; }
    protected function aggregateType(): string { return 'hardware_command'; }
    protected function aggregateId(): string { return (string) $this->commandId; }
    protected function channelSuffix(): string { return 'hardware'; }
    protected function data(): array
    {
        return [
            'command_id' => $this->commandId,
            'command_type' => $this->commandType,
            'spool_status' => $this->spoolStatus,
            'retry_count' => $this->retryCount,
        ];
    }
}
