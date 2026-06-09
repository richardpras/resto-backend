<?php

namespace App\Modules\Print\Listeners;

use App\Events\Hardware\CommandAcknowledged;
use App\Models\Modules\Hardware\Domain\HardwareCommandLog;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Modules\Hardware\Support\HardwareCommandType;
use App\Modules\Print\Services\PrintQueueProcessingService;
use App\Modules\Print\Services\PrintQueueStateService;
use Illuminate\Support\Facades\DB;

class CompletePrintJobOnHardwareCommandAck
{
    public function __construct(
        private readonly PrintQueueStateService $stateService,
        private readonly PrintQueueProcessingService $queueProcessingService,
    ) {}

    public function handle(CommandAcknowledged $event): void
    {
        if ($event->commandType() !== HardwareCommandType::PRINT_DOCUMENT) {
            return;
        }

        $command = HardwareCommandLog::query()->find($event->commandId());
        if ($command === null) {
            return;
        }

        $printJobId = (int) data_get($command->payload, 'printJobId', 0);
        if ($printJobId < 1) {
            $printJobId = (int) PrintJob::query()
                ->where('hardware_command_log_id', (int) $command->id)
                ->value('id');
        }
        if ($printJobId < 1) {
            return;
        }

        $spoolStatus = $event->spoolStatus();

        if ($spoolStatus === 'acknowledged') {
            $this->markCompleted($printJobId, (int) $command->outlet_id);

            return;
        }

        if (in_array($spoolStatus, ['failed', 'dead_letter'], true)) {
            $error = (string) ($command->last_error_message ?: 'Hardware bridge reported print failure.');
            $this->queueProcessingService->markBridgeFailure($printJobId, (int) $command->outlet_id, $error);
        }
    }

    private function markCompleted(int $printJobId, int $outletId): void
    {
        DB::transaction(function () use ($printJobId, $outletId): void {
            $job = PrintJob::query()
                ->whereKey($printJobId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();

            if ($job === null || (string) $job->status === 'done') {
                return;
            }

            $job->status = 'done';
            $job->last_error = null;
            $job->failure_context = null;
            $job->failed_at = null;
            $job->processed_at = now();
            $job->locked_at = null;
            $job->locked_by = null;
            $job->recovery_state = 'none';
            $job->retryable = true;
            $job->save();

            $this->stateService->appendEvent($job, 'bridge_ack', 'done', [
                'hardware_command_log_id' => $job->hardware_command_log_id,
            ]);
            $this->stateService->emitLifecycle($job->fresh(), 'done');
        });
    }
}
