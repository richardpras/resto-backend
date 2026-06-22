<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Modules\Notifications\Services\NotificationService;

final class OrderRecoveryNotificationAdapter
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyRecoveryEscalated(Order $order, OrderItem $item, ?string $reason): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $orderCode = (string) ($order->code ?? $order->id);
        $lineName = (string) ($item->name ?? 'Item');
        $message = trim($reason ?? '') !== ''
            ? sprintf('%s — %s', $lineName, trim($reason))
            : $lineName;

        $this->notificationService->fanOut(
            $outletId,
            'orders.recovery.approve',
            UserNotification::SEVERITY_WARNING,
            UserNotification::MODULE_SYSTEM,
            'recovery_pending',
            $order->id.'-'.$item->id,
            'Refund review needed · '.$orderCode,
            $message,
            '/orders?recoveryPending=1&orderId='.$order->id,
            [
                'orderId' => (int) $order->id,
                'orderItemId' => (int) $item->id,
                'orderCode' => $orderCode,
            ],
        );
    }
}
