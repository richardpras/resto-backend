<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrintJob;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrintQueueProcessingService
{
    public function __construct(
        private readonly PrintQueueStateService $stateService,
        private readonly PrintBridgeDispatchService $bridgeDispatch,
    ) {}

    public function processJob(int $printJobId, int $outletId, string $lockedBy = 'queue:print'): void
    {
        DB::transaction(function () use ($printJobId, $outletId, $lockedBy): void {
            $job = PrintJob::query()
                ->whereKey($printJobId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();

            if ($job === null || (string) $job->status === 'done') {
                return;
            }
            if ((string) $job->status === 'failed' && ! $job->retryable) {
                return;
            }
            if ($job->next_retry_at !== null && $job->next_retry_at->isFuture()) {
                return;
            }
            if ((string) $job->recovery_state === 'awaiting_ack' && $job->hardware_command_log_id !== null) {
                return;
            }

            $job->attempts = (int) $job->attempts + 1;
            $job->locked_at = now();
            $job->locked_by = $lockedBy;
            $job->last_attempt_at = now();
            $job->save();
            $this->stateService->appendEvent($job, 'processing', 'pending', [
                'attempt' => (int) $job->attempts,
            ]);
            $this->stateService->emitLifecycle($job->fresh(), 'processing');

            try {
                $command = $this->bridgeDispatch->dispatch($job->fresh());
                $job->hardware_command_log_id = (int) $command->id;
                $job->recovery_state = 'awaiting_ack';
                $job->locked_at = null;
                $job->locked_by = null;
                $job->save();
                $this->stateService->appendEvent($job, 'dispatched', 'pending', [
                    'hardware_command_log_id' => (int) $command->id,
                    'attempt' => (int) $job->attempts,
                ]);
                $this->stateService->emitLifecycle($job->fresh(), 'dispatched');
            } catch (RuntimeException $exception) {
                $this->markAttemptFailure($job, $exception->getMessage());
            }
        });
    }

    public function retryJob(int $printJobId, int $outletId): PrintJob
    {
        /** @var PrintJob $job */
        $job = PrintJob::query()
            ->whereKey($printJobId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();

        $job->status = 'pending';
        $job->retryable = true;
        $job->next_retry_at = now();
        $job->failed_at = null;
        $job->locked_at = null;
        $job->locked_by = null;
        $job->last_error = null;
        $job->hardware_command_log_id = null;
        $job->recovery_state = 'none';
        $job->save();
        $this->stateService->appendEvent($job, 'retry_requested', 'pending');
        $this->stateService->emitLifecycle($job->fresh(), 'retry-requested');
        app(PrintDispatchService::class)->dispatch((int) $job->id, $outletId);

        return $job;
    }

    public function markBridgeFailure(int $printJobId, int $outletId, string $error): void
    {
        DB::transaction(function () use ($printJobId, $outletId, $error): void {
            $job = PrintJob::query()
                ->whereKey($printJobId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();

            if ($job === null || (string) $job->status === 'done') {
                return;
            }

            $job->hardware_command_log_id = null;
            $this->markAttemptFailure($job, $error);
        });
    }

    private function markAttemptFailure(PrintJob $job, string $error): void
    {
        $maxAttempts = max(1, (int) $job->max_attempts);
        $hasRetries = (int) $job->attempts < $maxAttempts;
        $job->status = 'failed';
        $job->last_error = $error;
        $job->failure_context = [
            'attempt' => (int) $job->attempts,
            'max_attempts' => $maxAttempts,
        ];
        $job->failed_at = now();
        $job->locked_at = null;
        $job->locked_by = null;
        $job->retryable = $hasRetries;
        $job->next_retry_at = $hasRetries ? now()->addSeconds(15 * (int) $job->attempts) : null;
        $job->recovery_state = $hasRetries ? 'recoverable' : 'dead_letter';
        $job->save();

        $this->stateService->appendEvent($job, 'failed', 'failed', [
            'error' => $error,
            'retryable' => $hasRetries,
        ]);
        $this->stateService->emitLifecycle($job->fresh(), 'failed');
    }
}
