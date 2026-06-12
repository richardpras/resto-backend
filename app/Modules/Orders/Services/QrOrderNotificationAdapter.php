<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Modules\Notifications\Services\NotificationService;

class QrOrderNotificationAdapter
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function customerCallCashier(QrOrderRequest $request, string $reason): void
    {
        $sourceType = $reason === 'request_bill' ? 'customer_request_bill' : 'customer_call_cashier';
        $title = $reason === 'request_bill' ? 'Customer requests bill' : 'Customer needs assistance';
        $message = sprintf(
            'Table %s · %s (%s)',
            $request->table?->name ?? '—',
            (string) $request->request_code,
            $this->reasonLabel($reason),
        );

        $this->fanOutToCashierAndManager(
            (int) $request->outlet_id,
            $sourceType,
            (string) $request->id,
            $title,
            $message,
            '/qr-orders?search='.urlencode((string) $request->request_code),
            ['reason' => $reason, 'requestCode' => (string) $request->request_code],
        );
    }

    public function qrOrderAdjusted(QrOrderRequest $request): void
    {
        $this->fanOutToCashierAndManager(
            (int) $request->outlet_id,
            'qr_order_adjusted',
            (string) $request->id,
            'QR order adjusted',
            sprintf('Order %s was adjusted by cashier.', (string) $request->request_code),
            '/qr-orders?search='.urlencode((string) $request->request_code),
        );
    }

    public function qrOrderConfirmed(QrOrderRequest $request): void
    {
        $this->fanOutToCashierAndManager(
            (int) $request->outlet_id,
            'qr_order_confirmed',
            (string) $request->id,
            'QR order confirmed',
            sprintf('Order %s has been confirmed.', (string) $request->request_code),
            '/qr-orders?search='.urlencode((string) $request->request_code),
        );
    }

    public function qrOrderReadyOrServed(QrOrderRequest $request, string $phase): void
    {
        if ($phase !== 'ready' && $phase !== 'served') {
            return;
        }

        $this->fanOutToCashierAndManager(
            (int) $request->outlet_id,
            'qr_order_ready',
            (string) $request->id.'-'.$phase,
            $phase === 'ready' ? 'QR order ready' : 'QR order served',
            sprintf('Order %s is %s.', (string) $request->request_code, $phase),
            '/qr-orders?search='.urlencode((string) $request->request_code),
        );
    }

    /** @param array<string, mixed>|null $metadata */
    private function fanOutToCashierAndManager(
        int $outletId,
        string $sourceType,
        string $sourceId,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?array $metadata = null,
    ): void {
        $this->notificationService->fanOut(
            $outletId,
            'pos.use',
            UserNotification::SEVERITY_INFO,
            'orders',
            $sourceType,
            $sourceId,
            $title,
            $message,
            $actionUrl,
            $metadata,
        );

        $this->notificationService->fanOut(
            $outletId,
            'cashier.manage',
            UserNotification::SEVERITY_INFO,
            'orders',
            $sourceType,
            $sourceId.'-mgr',
            $title,
            $message,
            $actionUrl,
            $metadata,
        );
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'need_assistance' => 'Need Assistance',
            'request_bill' => 'Request Bill',
            'order_question' => 'Order Question',
            default => 'Other',
        };
    }
}
