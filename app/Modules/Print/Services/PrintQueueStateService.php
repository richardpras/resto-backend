<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrintJobEvent;
use App\Modules\Print\Events\PrinterQueueLifecycleChanged;

class PrintQueueStateService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function appendEvent(PrintJob $job, string $eventType, ?string $status = null, array $payload = []): void
    {
        PrintJobEvent::query()->create([
            'print_job_id' => (int) $job->id,
            'outlet_id' => $job->outlet_id !== null ? (int) $job->outlet_id : null,
            'event_type' => $eventType,
            'status' => $status,
            'payload' => $payload,
        ]);
    }

    public function emitLifecycle(PrintJob $job, string $stage): void
    {
        $outletId = (int) ($job->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        event(new PrinterQueueLifecycleChanged(
            outletId: $outletId,
            printJobId: (int) $job->id,
            status: (string) $job->status,
            type: (string) $job->type,
            stage: $stage,
            sequence: (int) $job->id,
            aggregateUpdatedAtIso: $job->updated_at?->toIso8601String()
        ));
    }
}
