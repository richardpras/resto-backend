<?php

namespace App\Console\Commands;

use App\Jobs\Payments\ProcessPaymentWebhookReceiptJob;
use App\Models\Modules\Payments\Domain\PaymentWebhookReceipt;
use App\Support\Observability\AsyncOperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ReplayPendingWebhookReceiptsCommand extends Command
{
    protected $signature = 'payments:replay-webhook-receipts {--limit=100 : Maximum pending webhook receipts to replay}';

    protected $description = 'Replay pending payment webhook receipts with durable retry queue dispatch';

    public function handle(): int
    {
        $commandContext = AsyncOperationContext::capture([
            'operation' => 'payments.replay_webhook_receipts',
            'command' => (string) $this->getName(),
        ]);
        AsyncOperationContext::apply($commandContext);

        $count = Cache::lock('payments:replay-webhook-receipts', 20)->block(3, function (): int {
            $limit = (int) $this->option('limit');
            $receipts = PaymentWebhookReceipt::query()
                ->whereNull('processed_at')
                ->where(function ($query): void {
                    $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($limit)
                ->get(['id']);

            foreach ($receipts as $receipt) {
                ProcessPaymentWebhookReceiptJob::dispatch(
                    (int) $receipt->id,
                    AsyncOperationContext::capture([
                        'operation' => 'payments.process_webhook_receipt',
                        'webhook_receipt_id' => (int) $receipt->id,
                    ])
                );
            }

            return $receipts->count();
        });

        $this->info('Dispatched '.$count.' pending webhook receipt replay jobs.');

        return self::SUCCESS;
    }
}
