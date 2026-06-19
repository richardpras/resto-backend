<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\Orders\Domain\Order;

class LoyaltySpendEarningService
{
    public function __construct(
        private readonly LoyaltyProgramService $programService,
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    public function processPaidOrder(Order $order): ?LoyaltyMemberLedger
    {
        if ($order->member_id === null || (string) $order->payment_status !== 'paid') {
            return null;
        }

        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return null;
        }

        $program = $this->programService->resolveActiveProgram(
            $outletId,
            LoyaltyProgram::TYPE_SPEND_BASED,
        );
        if ($program === null) {
            return null;
        }

        $config = $this->programService->loadRuleConfig($program);
        $points = $this->programService->calculateSpendBasedPoints(
            (float) ($order->subtotal ?? $order->total),
            $config,
        );
        if ($points <= 0) {
            return null;
        }

        $result = $this->ledgerService->createEarnFromOrder(
            memberId: (int) $order->member_id,
            loyaltyProgramId: (int) $program->id,
            orderId: (int) $order->id,
            points: $points,
        );

        if ($result['created']) {
            $this->balanceProjectionService->applyLedgerEntry($result['entry']);
            $this->loyaltyNotificationService->dispatchPointsEarned(
                $outletId,
                (int) $order->member_id,
                $points,
            );
        }

        return $result['entry'];
    }
}
