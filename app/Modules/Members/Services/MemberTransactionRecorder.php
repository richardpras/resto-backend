<?php

namespace App\Modules\Members\Services;

use App\Models\MemberTransaction;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use App\Modules\LoyaltyEngine\Services\LoyaltySpendEarningService;
use App\Modules\LoyaltyEngine\Services\LoyaltyTierRecalculationService;
use App\Modules\LoyaltyEngine\Services\LoyaltyVisitEarningService;

class MemberTransactionRecorder
{
    public function __construct(
        private readonly LoyaltySpendEarningService $loyaltySpendEarningService,
        private readonly LoyaltyVisitEarningService $loyaltyVisitEarningService,
        private readonly LoyaltyTierRecalculationService $loyaltyTierRecalculationService,
    ) {}

    public function recordForPaidOrder(Order $order): void
    {
        if ($order->member_id === null) {
            return;
        }

        if ((string) $order->payment_status !== 'paid') {
            return;
        }

        MemberTransaction::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'member_id' => (int) $order->member_id,
                'total_amount' => (float) $order->total,
                'transaction_at' => $order->updated_at ?? now(),
            ],
        );

        $this->loyaltySpendEarningService->processPaidOrder($order);
        $this->loyaltyVisitEarningService->processPaidOrder($order);

        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId > 0) {
            $this->loyaltyTierRecalculationService->recalculateForMember(
                (int) $order->member_id,
                $outletId,
                MemberTierHistory::REASON_RECALCULATION,
            );
        }
    }
}
