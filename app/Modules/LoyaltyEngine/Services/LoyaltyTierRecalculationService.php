<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;

class LoyaltyTierRecalculationService
{
    public function __construct(
        private readonly LoyaltyTierService $loyaltyTierService,
    ) {}

    public function recalculateForMember(int $memberId, int $outletId, string $reason = MemberTierHistory::REASON_RECALCULATION): void
    {
        if ($memberId < 1 || $outletId < 1) {
            return;
        }

        $member = Member::query()->find($memberId);
        if ($member === null || (int) $member->outlet_id !== $outletId) {
            return;
        }

        $this->loyaltyTierService->recalculateMemberTier($memberId, $outletId, $reason);
    }
}
