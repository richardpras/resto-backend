<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use App\Models\User;
use App\Modules\Members\Services\MemberService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyRewardRedemptionService
{
    public function __construct(
        private readonly MemberService $memberService,
        private readonly LoyaltyRewardService $rewardService,
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly LoyaltyTierRecalculationService $loyaltyTierRecalculationService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    /**
     * @return array{
     *     redemptionId: int,
     *     rewardName: string,
     *     pointsSpent: int,
     *     currentBalance: int,
     *     status: string
     * }
     */
    public function redeemReward(
        ?User $user,
        int $memberId,
        int $outletId,
        int $rewardId,
        ?string $notes = null,
    ): array {
        $member = $this->memberService->findForOutlet($user, $memberId, $outletId);
        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found for this outlet.'],
            ]);
        }

        if ((int) $member->outlet_id !== $outletId) {
            throw ValidationException::withMessages([
                'outletId' => ['Member does not belong to this outlet.'],
            ]);
        }

        if (! $member->is_active) {
            throw ValidationException::withMessages([
                'memberId' => ['Inactive members cannot redeem rewards.'],
            ]);
        }

        $reward = $this->rewardService->findScoped($user, $rewardId);
        if ($reward === null) {
            throw ValidationException::withMessages([
                'rewardId' => ['Reward not found.'],
            ]);
        }

        if ((int) $reward->outlet_id !== $outletId) {
            throw ValidationException::withMessages([
                'rewardId' => ['Reward does not belong to this outlet.'],
            ]);
        }

        if (! $reward->is_active) {
            throw ValidationException::withMessages([
                'rewardId' => ['Reward is not active.'],
            ]);
        }

        $pointsCost = (int) $reward->points_cost;

        $result = DB::transaction(function () use ($member, $outletId, $reward, $pointsCost, $notes): array {
            $balance = MemberLoyaltyBalance::query()
                ->where('member_id', (int) $member->id)
                ->lockForUpdate()
                ->first();

            $currentBalance = (int) ($balance->current_points ?? 0);
            if ($currentBalance < $pointsCost) {
                throw ValidationException::withMessages([
                    'points' => ['Insufficient loyalty balance.'],
                ]);
            }

            $redemption = LoyaltyRewardRedemption::query()->create([
                'outlet_id' => $outletId,
                'member_id' => (int) $member->id,
                'reward_id' => (int) $reward->id,
                'points_spent' => $pointsCost,
                'status' => LoyaltyRewardRedemption::STATUS_ISSUED,
                'issued_at' => now(),
                'notes' => $notes,
            ]);

            $entry = $this->ledgerService->createRewardRedeem(
                memberId: (int) $member->id,
                redemptionId: (int) $redemption->id,
                pointsToRedeem: $pointsCost,
                description: 'Reward: '.$reward->name,
            );

            $balance = $this->balanceProjectionService->applyLedgerEntry($entry);

            $this->loyaltyTierRecalculationService->recalculateForMember(
                (int) $member->id,
                $outletId,
                MemberTierHistory::REASON_RECALCULATION,
            );

            return [
                'redemptionId' => (int) $redemption->id,
                'rewardName' => (string) $reward->name,
                'pointsSpent' => $pointsCost,
                'currentBalance' => (int) $balance->current_points,
                'status' => LoyaltyRewardRedemption::STATUS_ISSUED,
            ];
        });

        $this->loyaltyNotificationService->dispatchRewardRedeemed(
            $outletId,
            (int) $member->id,
            (string) $result['rewardName'],
            (int) $result['pointsSpent'],
        );

        app(LoyaltyAutomationService::class)->safeProcessEvent(
            $outletId,
            (int) $member->id,
            LoyaltyAutomation::TRIGGER_REWARD_REDEEMED,
        );

        return $result;
    }

    /**
     * @return Collection<int, LoyaltyRewardRedemption>
     */
    public function listForMember(?User $user, int $memberId, int $outletId): Collection
    {
        $member = $this->memberService->findForOutlet($user, $memberId, $outletId);
        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found for this outlet.'],
            ]);
        }

        return LoyaltyRewardRedemption::query()
            ->where('member_id', $member->id)
            ->where('outlet_id', $outletId)
            ->with('reward')
            ->orderByDesc('issued_at')
            ->limit(50)
            ->get();
    }

    public function updateStatus(?User $user, int $redemptionId, string $status): LoyaltyRewardRedemption
    {
        $redemption = $this->findScoped($user, $redemptionId);
        if ($redemption === null) {
            throw ValidationException::withMessages([
                'redemptionId' => ['Redemption not found.'],
            ]);
        }

        if ($redemption->status !== LoyaltyRewardRedemption::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'status' => ['Only issued redemptions can be updated.'],
            ]);
        }

        if (! in_array($status, [
            LoyaltyRewardRedemption::STATUS_FULFILLED,
            LoyaltyRewardRedemption::STATUS_CANCELLED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Status must be fulfilled or cancelled.'],
            ]);
        }

        $attributes = ['status' => $status];
        if ($status === LoyaltyRewardRedemption::STATUS_FULFILLED) {
            $attributes['fulfilled_at'] = now();
        } else {
            $attributes['cancelled_at'] = now();
        }

        $redemption->update($attributes);

        return $redemption->fresh(['reward']);
    }

    public function findScoped(?User $user, int $redemptionId): ?LoyaltyRewardRedemption
    {
        $redemption = LoyaltyRewardRedemption::query()->with('reward')->whereKey($redemptionId)->first();
        if ($redemption === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $redemption->outlet_id);

        return $redemption;
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}
