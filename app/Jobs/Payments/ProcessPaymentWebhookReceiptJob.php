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

class ProcessPaymentWebhookReceiptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [10, 30, 90, 180, 300, 600];

    public function __construct(
        public readonly int $receiptId,
        /** @var array<string,mixed> */
        public readonly array $observabilityContext = [],
    ) {
        $this->onQueue((string) config('queue.routing.payment_webhooks_queue', 'payments-webhooks'));
    }

    public function uniqueId(): string
    {
        return 'payment-webhook-receipt-'.$this->receiptId;
    }

    public function handle(PaymentGatewayService $paymentGatewayService): void
    {
        $jobContext = AsyncOperationContext::withQueueMetadata($this->observabilityContext, [
            'operation' => 'payments.process_webhook_receipt',
            'webhook_receipt_id' => $this->receiptId,
            'job_name' => static::class,
            'job_id' => method_exists($this->job, 'getJobId') ? $this->job->getJobId() : null,
            'queue' => method_exists($this->job, 'getQueue') ? $this->job->getQueue() : null,
            'attempt' => $this->attempts(),
        ]);
        AsyncOperationContext::apply($jobContext);

        Log::info('Processing payment webhook receipt job.', $jobContext);
        $paymentGatewayService->processWebhookReceipt($this->receiptId);
    }

    public function failed(Throwable $throwable): void
    {
        $failedContext = AsyncOperationContext::withQueueMetadata($this->observabilityContext, [
            'operation' => 'payments.process_webhook_receipt',
            'webhook_receipt_id' => $this->receiptId,
            'job_name' => static::class,
            'job_id' => method_exists($this->job, 'getJobId') ? $this->job->getJobId() : null,
            'queue' => method_exists($this->job, 'getQueue') ? $this->job->getQueue() : null,
            'attempt' => $this->attempts(),
            'error' => $throwable->getMessage(),
        ]);
        AsyncOperationContext::apply($failedContext);
        Log::error('Payment webhook receipt job failed.', $failedContext);

        app(PaymentGatewayService::class)->markWebhookReceiptDeadLetter($this->receiptId, $throwable->getMessage());
    }
}
