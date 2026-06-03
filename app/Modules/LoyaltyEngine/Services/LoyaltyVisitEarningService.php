<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\Orders\Domain\Order;

class LoyaltyVisitEarningService
{
    public function __construct(
        private readonly LoyaltyProgramService $programService,
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
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
            LoyaltyProgram::TYPE_VISIT_BASED,
        );
        if ($program === null) {
            return null;
        }

        $config = $this->programService->loadRuleConfig($program);
        $visitThreshold = (int) ($config['visit_threshold'] ?? 0);
        $pointsAwarded = (int) ($config['points_awarded'] ?? 0);

        if ($visitThreshold <= 0 || $pointsAwarded <= 0) {
            return null;
        }

        $visitCount = (int) MemberTransaction::query()
            ->where('member_id', (int) $order->member_id)
            ->count();

        if ($visitCount < 1 || $visitCount % $visitThreshold !== 0) {
            return null;
        }

        $result = $this->ledgerService->createVisitReward(
            memberId: (int) $order->member_id,
            loyaltyProgramId: (int) $program->id,
            milestoneVisit: $visitCount,
            points: $pointsAwarded,
        );

        if ($result['created']) {
            $this->balanceProjectionService->applyLedgerEntry($result['entry']);
        }

        return $result['entry'];
    }
}
