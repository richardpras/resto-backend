<?php

namespace App\Modules\LoyaltyEngine\Listeners;

use App\Modules\LoyaltyEngine\Services\VoucherOrderLifecycleService;
use App\Modules\Orders\Events\OrderLifecycleChanged;

class RedeemVoucherOnOrderPaidListener
{
    public function __construct(
        private readonly VoucherOrderLifecycleService $voucherOrderLifecycleService,
    ) {}

    public function handle(OrderLifecycleChanged $event): void
    {
        $this->voucherOrderLifecycleService->handleOrderLifecycleChanged($event);
    }
}
