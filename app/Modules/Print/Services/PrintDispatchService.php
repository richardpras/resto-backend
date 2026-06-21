<?php

namespace App\Modules\Print\Services;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Print\Domain\PrintJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PrintDispatchService
{
    public const MODE_QUEUE_WORKER = 'queue_worker';

    public const MODE_SYNC_DISPATCH = 'sync_dispatch';

    public const MODE_SCHEDULED_DISPATCH = 'scheduled_dispatch';

    public function __construct(
        private readonly PrintQueueProcessingService $processingService,
    ) {}

    public function mode(): string
    {
        $mode = (string) config('print.dispatch.mode', self::MODE_QUEUE_WORKER);

        return in_array($mode, [self::MODE_QUEUE_WORKER, self::MODE_SYNC_DISPATCH, self::MODE_SCHEDULED_DISPATCH], true)
            ? $mode
            : self::MODE_QUEUE_WORKER;
    }

    public function dispatchAfterEnqueue(PrintJob $job): void
    {
        if ($job->wasRecentlyCreated) {
            $this->dispatch((int) $job->id, (int) $job->outlet_id);

            return;
        }

        if ($this->shouldRedispatch($job)) {
            $prepared = $this->prepareForRedispatch($job);
            $this->dispatch((int) $prepared->id, (int) $prepared->outlet_id);
        }
    }

    public function shouldRedispatch(PrintJob $job): bool
    {
        if ((string) $job->status === 'done') {
            return false;
        }

        if ((string) $job->recovery_state === 'awaiting_ack' && $job->hardware_command_log_id !== null) {
            return false;
        }

        if ((string) $job->status === 'pending') {
            return true;
        }

        if ((string) $job->status === 'failed' && $job->retryable) {
            return true;
        }

        return (string) $job->recovery_state === 'dead_letter';
    }

    private function prepareForRedispatch(PrintJob $job): PrintJob
    {
        if ((string) $job->recovery_state === 'dead_letter'
            || ((string) $job->status === 'failed' && ! $job->retryable)) {
            $job->status = 'pending';
            $job->retryable = true;
            $job->recovery_state = 'none';
            $job->next_retry_at = now();
            $job->failed_at = null;
            $job->last_error = null;
            $job->hardware_command_log_id = null;
            $job->locked_at = null;
            $job->locked_by = null;
            $job->save();

            return $job->fresh() ?? $job;
        }

        return $job;
    }

    public function dispatch(int $printJobId, int $outletId): void
    {
        match ($this->mode()) {
            self::MODE_SYNC_DISPATCH => $this->processingService->processJob($printJobId, $outletId, 'sync:print'),
            self::MODE_SCHEDULED_DISPATCH => null,
            default => ProcessPrintJob::dispatch($printJobId, $outletId),
        };
    }

    /**
     * @return array{processed:int,skipped:int}
     */
    public function processPendingBatch(?int $outletId = null, ?int $limit = null): array
    {
        $batchLimit = max(1, $limit ?? (int) config('print.dispatch.scheduled_batch_limit', 50));
        $jobs = $this->pendingJobsQuery($outletId)
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();

        $processed = 0;
        $skipped = 0;

        foreach ($jobs as $job) {
            if (! $job instanceof PrintJob) {
                $skipped++;

                continue;
            }

            $statusBefore = (string) $job->status;
            $attemptsBefore = (int) $job->attempts;
            $this->processingService->processJob((int) $job->id, (int) $job->outlet_id, 'scheduler:print');
            $fresh = $job->fresh();

            if ($fresh === null) {
                $skipped++;

                continue;
            }

            if ((string) $fresh->status !== $statusBefore || (int) $fresh->attempts !== $attemptsBefore) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return Builder<PrintJob>
     */
    public function pendingJobsQuery(?int $outletId = null): Builder
    {
        return PrintJob::query()
            ->when($outletId !== null, fn (Builder $query) => $query->where('outlet_id', $outletId))
            ->where(function (Builder $query): void {
                $query->where('status', 'pending')
                    ->orWhere(function (Builder $retryable): void {
                        $retryable->where('status', 'failed')
                            ->where('retryable', true);
                    });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->where('recovery_state', '!=', 'awaiting_ack')
                    ->orWhereNull('hardware_command_log_id');
            });
    }

    /**
     * @return array<string,int|string>
     */
    public function queueCounters(int $outletId): array
    {
        $base = PrintJob::query()->where('outlet_id', $outletId);

        return [
            'dispatchMode' => $this->mode(),
            'pending' => (int) (clone $base)->where('status', 'pending')->count(),
            'failed' => (int) (clone $base)->where('status', 'failed')->count(),
            'retried' => (int) (clone $base)->where('attempts', '>', 1)->whereIn('status', ['pending', 'failed'])->count(),
            'recoverable' => (int) (clone $base)->where('recovery_state', 'recoverable')->count(),
            'deadLetter' => (int) (clone $base)->where('recovery_state', 'dead_letter')->count(),
        ];
    }
}
