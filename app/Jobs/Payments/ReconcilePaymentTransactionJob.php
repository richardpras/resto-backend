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
use Throwable;

class ReconcilePaymentTransactionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [15, 45, 120, 240, 600];

    public function __construct(
        public readonly int $transactionId,
        /** @var array<string,mixed> */
        public readonly array $observabilityContext = [],
    ) {
        $this->onQueue((string) config('queue.routing.payment_reconciliation_queue', 'payments-reconciliation'));
    }

    public function uniqueId(): string
    {
        return 'payment-reconcile-'.$this->transactionId;
    }

    public function handle(PaymentGatewayService $paymentGatewayService): void
    {
        $jobContext = AsyncOperationContext::withQueueMetadata($this->observabilityContext, [
            'operation' => 'payments.reconcile_transaction',
            'transaction_id' => $this->transactionId,
            'job_name' => static::class,
            'job_id' => method_exists($this->job, 'getJobId') ? $this->job->getJobId() : null,
            'queue' => method_exists($this->job, 'getQueue') ? $this->job->getQueue() : null,
            'attempt' => $this->attempts(),
        ]);
        AsyncOperationContext::apply($jobContext);

        Log::info('Reconciling payment transaction job.', $jobContext);
        $paymentGatewayService->reconcileTransactionById($this->transactionId);
    }

    public function failed(Throwable $throwable): void
    {
        $failedContext = AsyncOperationContext::withQueueMetadata($this->observabilityContext, [
            'operation' => 'payments.reconcile_transaction',
            'transaction_id' => $this->transactionId,
            'job_name' => static::class,
            'job_id' => method_exists($this->job, 'getJobId') ? $this->job->getJobId() : null,
            'queue' => method_exists($this->job, 'getQueue') ? $this->job->getQueue() : null,
            'attempt' => $this->attempts(),
            'error' => $throwable->getMessage(),
        ]);
        AsyncOperationContext::apply($failedContext);
        Log::error('Payment reconciliation job failed.', $failedContext);

        app(PaymentGatewayService::class)->markTransactionAsyncFailure($this->transactionId, $throwable->getMessage());
    }
}
