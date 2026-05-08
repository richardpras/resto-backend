<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrintJob;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrintQueueProcessingService
{
    public function __construct(
        private readonly PrintQueueStateService $stateService,
    ) {}

    public function processJob(int $printJobId, int $outletId): void
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
            if ((string) $job->status === 'failed' && ! $job->retryable) {
                return;
            }
            if ($job->next_retry_at !== null && $job->next_retry_at->isFuture()) {
                return;
            }

            $job->attempts = (int) $job->attempts + 1;
            $job->locked_at = now();
            $job->locked_by = 'queue:print';
            $job->last_attempt_at = now();
            $job->save();
            $this->stateService->appendEvent($job, 'processing', 'pending', [
                'attempt' => (int) $job->attempts,
            ]);
            $this->stateService->emitLifecycle($job->fresh(), 'processing');

            try {
                $this->simulateAgentSubmission($job);
                $job->status = 'done';
                $job->last_error = null;
                $job->failure_context = null;
                $job->failed_at = null;
                $job->processed_at = now();
                $job->locked_at = null;
                $job->locked_by = null;
                $job->recovery_state = 'none';
                $job->save();
                $this->stateService->appendEvent($job, 'done', 'done');
                $this->stateService->emitLifecycle($job->fresh(), 'done');
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
        $job->save();
        $this->stateService->appendEvent($job, 'retry_requested', 'pending');
        $this->stateService->emitLifecycle($job->fresh(), 'retry-requested');

        return $job;
    }

    private function simulateAgentSubmission(PrintJob $job): void
    {
        if ((bool) data_get($job->printable_snapshot, 'simulate_failure') === true) {
            throw new RuntimeException('Simulated print delivery failure.');
        }

        if ($job->printer_profile_id !== null) {
            $profile = PrinterProfile::query()->find($job->printer_profile_id);
            if ($profile !== null && ! $profile->is_active) {
                throw new RuntimeException('Printer profile is inactive.');
            }
        }
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
