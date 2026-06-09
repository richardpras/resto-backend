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
    ) {}

    public function onOrderPaid(?User $user, Order $order): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
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
}
