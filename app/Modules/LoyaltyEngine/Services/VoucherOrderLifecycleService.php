<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Orders\Events\OrderLifecycleChanged;

class VoucherOrderLifecycleService
{
    public function __construct(
        private readonly VoucherRedemptionService $voucherRedemptionService,
    ) {}

    public function handleOrderLifecycleChanged(OrderLifecycleChanged $event): void
    {
        if ($event->getPaymentStatus() !== 'paid') {
            return;
        }

        if ($event->getOrderStatus() === 'cancelled') {
            return;
        }

        $order = Order::query()->find($event->getOrderId());
        if ($order === null) {
            return;
        }

        $this->voucherRedemptionService->redeemForPaidOrder($order);
    }
}
