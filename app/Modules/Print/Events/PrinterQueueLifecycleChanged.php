<?php

namespace App\Modules\Print\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class PrinterQueueLifecycleChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $printJobId,
        private readonly string $status,
        private readonly string $type,
        private readonly ?string $stage = null,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'printer.queue.lifecycle.changed';
    }

    protected function aggregateType(): string
    {
        return 'print_job';
    }

    protected function aggregateId(): string
    {
        return (string) $this->printJobId;
    }

    protected function channelSuffix(): string
    {
        return 'printer-queue';
    }

    protected function data(): array
    {
        return [
            'print_job_id' => $this->printJobId,
            'status' => $this->status,
            'type' => $this->type,
            'stage' => $this->stage,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
