<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use App\Models\User;
use App\Modules\Members\Services\MemberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyRedeemService
{
    public function __construct(
        private readonly MemberService $memberService,
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
        private readonly LoyaltyTierRecalculationService $loyaltyTierRecalculationService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    /**
     * @return array{memberId: int, redeemedPoints: int, currentBalance: int}
     */
    public function redeemMemberPoints(
        ?User $user,
        int $memberId,
        int $outletId,
        int $points,
        ?string $description = null,
    ): array {
        if ($points <= 0) {
            throw ValidationException::withMessages([
                'points' => ['Redemption points must be greater than zero.'],
            ]);
        }

        $member = $this->memberService->findForOutlet($user, $memberId, $outletId);
        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found for this outlet.'],
            ]);
        }

        if (! $member->is_active) {
            throw ValidationException::withMessages([
                'memberId' => ['Inactive members cannot redeem points.'],
            ]);
        }

        $result = DB::transaction(function () use ($member, $points, $description, $outletId): array {
            $balance = MemberLoyaltyBalance::query()
                ->where('member_id', (int) $member->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = (int) ($balance->current_points ?? 0);

            if ($currentBalance < $points) {
                throw ValidationException::withMessages([
                    'points' => ['Insufficient loyalty balance.'],
                ]);
            }

            $entry = $this->ledgerService->createRedeem(
                memberId: (int) $member->id,
                pointsToRedeem: $points,
                description: $description,
            );

            $balance = $this->balanceProjectionService->applyLedgerEntry($entry);

            $this->loyaltyTierRecalculationService->recalculateForMember(
                (int) $member->id,
                $outletId,
                MemberTierHistory::REASON_RECALCULATION,
            );

            return [
                'memberId' => (int) $member->id,
                'redeemedPoints' => $points,
                'currentBalance' => (int) $balance->current_points,
            ];
        });

        $this->loyaltyNotificationService->dispatchPointsRedeemed($outletId, (int) $member->id, $points);

        return $result;
    }
}
