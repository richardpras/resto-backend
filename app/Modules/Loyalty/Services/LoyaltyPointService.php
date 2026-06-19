<?php

namespace App\Modules\Loyalty\Services;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\Loyalty\Domain\LoyaltyRewardRedemption;
use App\Models\User;
use App\Modules\Loyalty\Events\CustomerLoyaltyUpdated;
use App\Modules\Loyalty\Events\RewardRedemptionCreated;
use App\Modules\Members\Services\MemberPointsMirrorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyPointService
{
    public function __construct(
        private readonly MembershipTierService $membershipTierService,
    ) {}

    /** @return array{ledger:LoyaltyPointsLedger,account:LoyaltyAccount,idempotent:bool} */
    public function accrue(User $user, LoyaltyAccount $account, array $payload): array
    {
        $outletId = (int) $payload['outletId'];
        $idempotencyKey = (string) $payload['idempotencyKey'];
        $this->assertWithinReplayWindow(isset($payload['clientOccurredAt']) ? (string) $payload['clientOccurredAt'] : null);

        return DB::transaction(function () use ($user, $account, $outletId, $idempotencyKey, $payload): array {
            $existing = LoyaltyPointsLedger::query()
                ->where('outlet_id', $outletId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof LoyaltyPointsLedger) {
                $freshAccount = LoyaltyAccount::query()->findOrFail($existing->loyalty_account_id);

                return ['ledger' => $existing, 'account' => $freshAccount, 'idempotent' => true];
            }

            $lockedAccount = LoyaltyAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $pointsDelta = (int) $payload['pointsDelta'];
            $spendAmount = isset($payload['spendAmount']) ? (float) $payload['spendAmount'] : 0.0;
            $visitIncrement = isset($payload['visitIncrement']) ? (int) $payload['visitIncrement'] : 0;
            $multiplier = $this->membershipTierService->multiplierFor($lockedAccount->currentTier);
            $effectivePoints = (int) floor(max(0, $pointsDelta) * $multiplier);
            $balanceBefore = (int) $lockedAccount->points_balance;
            $balanceAfter = max(0, $balanceBefore + $effectivePoints);

            $ledger = LoyaltyPointsLedger::query()->create([
                'loyalty_account_id' => (int) $lockedAccount->id,
                'outlet_id' => $outletId,
                'created_by_user_id' => (int) $user->id,
                'idempotency_key' => $idempotencyKey,
                'transaction_type' => 'accrual',
                'points_delta' => $effectivePoints,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'spend_amount' => $spendAmount,
                'visit_increment' => $visitIncrement,
                'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
                'client_occurred_at' => isset($payload['clientOccurredAt']) ? $payload['clientOccurredAt'] : null,
                'applied_at' => now(),
            ]);

            $lockedAccount->update([
                'points_balance' => $balanceAfter,
                'lifetime_points_earned' => (int) $lockedAccount->lifetime_points_earned + max(0, $effectivePoints),
                'lifetime_spend' => (float) $lockedAccount->lifetime_spend + max(0, $spendAmount),
                'lifetime_visits' => (int) $lockedAccount->lifetime_visits + max(0, $visitIncrement),
                'last_activity_at' => now(),
            ]);
            $freshAccount = $this->membershipTierService->evaluateAndApply($lockedAccount->fresh(['currentTier']) ?? $lockedAccount);

            event(new CustomerLoyaltyUpdated(
                $outletId,
                (int) $freshAccount->id,
                (int) $freshAccount->points_balance,
                $effectivePoints,
                'accrual',
                null,
                $freshAccount->updated_at?->toIso8601String(),
            ));

            if (! MemberPointsMirrorService::isMirrorOriginKey($idempotencyKey)) {
                app(MemberPointsMirrorService::class)->mirrorCrmAccrualToEngine(
                    $freshAccount,
                    $outletId,
                    $effectivePoints,
                    $idempotencyKey,
                    $user,
                );
            }

            return ['ledger' => $ledger, 'account' => $freshAccount, 'idempotent' => false];
        });
    }

    /** @return array{redemption:LoyaltyRewardRedemption,account:LoyaltyAccount,idempotent:bool} */
    public function redeem(User $user, LoyaltyAccount $account, array $payload): array
    {
        $outletId = (int) $payload['outletId'];
        $idempotencyKey = (string) $payload['idempotencyKey'];
        $this->assertWithinReplayWindow(isset($payload['clientOccurredAt']) ? (string) $payload['clientOccurredAt'] : null);

        return DB::transaction(function () use ($user, $account, $outletId, $idempotencyKey, $payload): array {
            $existing = LoyaltyRewardRedemption::query()
                ->where('outlet_id', $outletId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof LoyaltyRewardRedemption) {
                $freshAccount = LoyaltyAccount::query()->findOrFail($existing->loyalty_account_id);

                return ['redemption' => $existing, 'account' => $freshAccount, 'idempotent' => true];
            }

            $lockedAccount = LoyaltyAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $pointsCost = (int) $payload['pointsCost'];
            $balanceBefore = (int) $lockedAccount->points_balance;
            if ($balanceBefore < $pointsCost) {
                throw ValidationException::withMessages([
                    'pointsCost' => ['Insufficient loyalty points for this redemption.'],
                ]);
            }

            $balanceAfter = $balanceBefore - $pointsCost;
            $ledger = LoyaltyPointsLedger::query()->create([
                'loyalty_account_id' => (int) $lockedAccount->id,
                'outlet_id' => $outletId,
                'created_by_user_id' => (int) $user->id,
                'idempotency_key' => 'redeem-'.$idempotencyKey,
                'transaction_type' => 'redeem',
                'points_delta' => -$pointsCost,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'spend_amount' => 0,
                'visit_increment' => 0,
                'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
                'client_occurred_at' => isset($payload['clientOccurredAt']) ? $payload['clientOccurredAt'] : null,
                'applied_at' => now(),
            ]);

            $redemption = LoyaltyRewardRedemption::query()->create([
                'loyalty_account_id' => (int) $lockedAccount->id,
                'outlet_id' => $outletId,
                'created_by_user_id' => (int) $user->id,
                'ledger_entry_id' => (int) $ledger->id,
                'idempotency_key' => $idempotencyKey,
                'reward_code' => (string) $payload['rewardCode'],
                'points_cost' => $pointsCost,
                'status' => 'created',
                'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
                'redeemed_at' => now(),
            ]);

            $lockedAccount->update([
                'points_balance' => $balanceAfter,
                'lifetime_points_redeemed' => (int) $lockedAccount->lifetime_points_redeemed + $pointsCost,
                'last_activity_at' => now(),
            ]);
            $freshAccount = $this->membershipTierService->evaluateAndApply($lockedAccount->fresh(['currentTier']) ?? $lockedAccount);

            event(new RewardRedemptionCreated(
                $outletId,
                (int) $redemption->id,
                (int) $freshAccount->id,
                (string) $redemption->reward_code,
                (int) $redemption->points_cost,
                null,
                $redemption->updated_at?->toIso8601String(),
            ));
            event(new CustomerLoyaltyUpdated(
                $outletId,
                (int) $freshAccount->id,
                (int) $freshAccount->points_balance,
                -$pointsCost,
                'redeem',
                null,
                $freshAccount->updated_at?->toIso8601String(),
            ));

            if (! MemberPointsMirrorService::isMirrorOriginKey($idempotencyKey)) {
                app(MemberPointsMirrorService::class)->mirrorCrmRedemptionToEngine(
                    $freshAccount,
                    $outletId,
                    $pointsCost,
                    $idempotencyKey,
                    $user,
                );
            }

            return ['redemption' => $redemption, 'account' => $freshAccount, 'idempotent' => false];
        });
    }

    private function assertWithinReplayWindow(?string $clientOccurredAtIso): void
    {
        if (! is_string($clientOccurredAtIso) || trim($clientOccurredAtIso) === '') {
            return;
        }

        $maxHours = max(1, (int) config('loyalty.replay_max_age_hours', 336));
        $occurred = CarbonImmutable::parse($clientOccurredAtIso)->utc();
        if ($occurred->lt(now()->utc()->subHours($maxHours))) {
            throw ValidationException::withMessages([
                'clientOccurredAt' => ['Operation is outside the allowed replay window. Refresh and retry.'],
            ]);
        }
    }
}
