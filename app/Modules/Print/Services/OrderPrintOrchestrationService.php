<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use App\Modules\Print\Support\ReceiptDocumentKind;
use Illuminate\Support\Facades\Log;

class OrderPrintOrchestrationService
{
    public function __construct(
        private readonly ReceiptDocumentService $receiptDocuments,
        private readonly PrinterRoutingService $printerRouting,
        private readonly ReceiptOrderSnapshotBuilder $orderSnapshotBuilder,
    ) {}

    public function onOrderPaid(?User $user, Order $order): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        if ($this->orderHasSplits($order)) {
            return;
        }

        if ($user !== null) {
            try {
                $this->receiptDocuments->render(
                    $user,
                    $outletId,
                    ReceiptDocumentKind::CustomerReceipt,
                    'order',
                    (int) $order->id,
                    null,
                    ['queuePrint' => true, 'generatePdf' => false, 'issueFiscal' => false],
                );

                return;
            } catch (\Throwable $exception) {
                Log::warning('print.receipt_render_failed', [
                    'order_id' => (int) $order->id,
                    'outlet_id' => $outletId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->printerRouting->queueReceiptForOrder($order, 'order-paid-fallback');
    }

    /**
     * @param  list<int>  $affectedSplitIds
     */
    public function maybeQueueSplitReceiptsAfterPayment(User $user, Order $order, array $affectedSplitIds): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1 || $affectedSplitIds === []) {
            return;
        }

        $order->loadMissing(['payments', 'orderPromotion', 'orderVoucher', 'splits.items']);

        foreach (array_values(array_unique(array_filter($affectedSplitIds, static fn (int $id): bool => $id > 0))) as $splitId) {
            if (! $this->orderSnapshotBuilder->isSplitFullyPaid($order, $splitId)) {
                continue;
            }

            try {
                $this->receiptDocuments->render(
                    $user,
                    $outletId,
                    ReceiptDocumentKind::CustomerReceipt,
                    'order',
                    (int) $order->id,
                    $splitId,
                    ['queuePrint' => true, 'generatePdf' => false, 'issueFiscal' => false],
                );
            } catch (\Throwable $exception) {
                Log::warning('print.split_receipt_render_failed', [
                    'order_id' => (int) $order->id,
                    'order_split_id' => $splitId,
                    'outlet_id' => $outletId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function orderHasSplits(Order $order): bool
    {
        if ($order->relationLoaded('splits')) {
            return $order->splits->isNotEmpty();
        }

        return $order->splits()->exists();
    }
}
