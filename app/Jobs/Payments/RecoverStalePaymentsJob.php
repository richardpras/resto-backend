<?php

namespace App\Jobs\Payments;

use App\Modules\Payments\Services\PaymentGatewayService;
use App\Support\Observability\AsyncOperationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecoverStalePaymentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $limit = 100,
        /** @var array<string,mixed> */
        public readonly array $observabilityContext = [],
    ) {
        $this->onQueue((string) config('queue.routing.payment_recovery_queue', 'payments-recovery'));
    }

    public function uniqueId(): string
    {
        return 'recover-stale-payments';
    }

    public function handle(PaymentGatewayService $paymentGatewayService): void
    {
        $jobContext = AsyncOperationContext::withQueueMetadata($this->observabilityContext, [
            'operation' => 'payments.recover_stale',
            'job_name' => static::class,
            'job_id' => method_exists($this->job, 'getJobId') ? $this->job->getJobId() : null,
            'queue' => method_exists($this->job, 'getQueue') ? $this->job->getQueue() : null,
            'attempt' => $this->attempts(),
        ]);
        AsyncOperationContext::apply($jobContext);

        Log::info('Recover stale payments job dispatched reconciliation.', $jobContext);
        $paymentGatewayService->dispatchPendingReconciliation($this->limit, $jobContext);
    }
}
