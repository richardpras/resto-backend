<?php

namespace App\Modules\Members\Services;

use App\Models\Member;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use Illuminate\Support\Facades\DB;

class MemberPointsMirrorService
{
    public const MIRROR_ENGINE_PREFIX = 'mirror-engine-';

    public const MIRROR_CRM_PREFIX = 'mirror-crm-';

    public function __construct(
        private readonly MemberLoyaltyAccountLinker $memberLoyaltyAccountLinker,
        private readonly LoyaltyLedgerService $loyaltyLedgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
    ) {}

    public static function isMirrorOriginKey(string $idempotencyKey): bool
    {
        return str_starts_with($idempotencyKey, self::MIRROR_ENGINE_PREFIX)
            || str_starts_with($idempotencyKey, self::MIRROR_CRM_PREFIX);
    }

    public function mirrorEngineEntryToCrm(LoyaltyMemberLedger $entry, ?User $user = null): bool
    {
        $points = (int) $entry->points;
        if ($points === 0) {
            return false;
        }

        $member = Member::query()->whereKey($entry->member_id)->first();
        if ($member === null) {
            return false;
        }

        $outletId = (int) ($member->outlet_id ?? 0);
        if ($outletId < 1) {
            return false;
        }

        $account = $this->memberLoyaltyAccountLinker->ensureForMember($member);
        $idempotencyKey = self::MIRROR_ENGINE_PREFIX.$entry->id;
        $transactionType = $points > 0 ? 'accrual' : 'redeem';

        return $this->writeCrmLedgerDelta(
            account: $account,
            outletId: $outletId,
            idempotencyKey: $idempotencyKey,
            pointsDelta: $points,
            transactionType: $transactionType,
            user: $user,
            meta: [
                'source' => 'loyalty_engine_mirror',
                'engineLedgerId' => (int) $entry->id,
                'engineType' => (string) $entry->type,
                'referenceType' => $entry->reference_type,
                'referenceId' => $entry->reference_id,
            ],
        );
    }

    public function mirrorCrmRedemptionToEngine(
        LoyaltyAccount $account,
        int $outletId,
        int $pointsCost,
        string $crmIdempotencyKey,
        ?User $user = null,
    ): bool {
        if ($pointsCost <= 0 || self::isMirrorOriginKey($crmIdempotencyKey)) {
            return false;
        }

        $member = $this->memberLoyaltyAccountLinker->findMemberByLoyaltyAccountId((int) $account->id);
        if ($member === null) {
            return false;
        }

        $engineReferenceId = self::MIRROR_CRM_PREFIX.$outletId.'-'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $crmIdempotencyKey);
        $existing = LoyaltyMemberLedger::query()
            ->where('member_id', (int) $member->id)
            ->where('type', LoyaltyMemberLedger::TYPE_REDEEM)
            ->where('reference_type', 'crm_mirror')
            ->where('reference_id', $engineReferenceId)
            ->exists();

        if ($existing) {
            return false;
        }

        $entry = $this->loyaltyLedgerService->createEntry(
            memberId: (int) $member->id,
            type: LoyaltyMemberLedger::TYPE_REDEEM,
            points: -$pointsCost,
            referenceType: 'crm_mirror',
            referenceId: $engineReferenceId,
            description: 'Mirrored CRM POS redemption',
        );

        $this->balanceProjectionService->applyLedgerEntry($entry, skipMirror: true);

        return true;
    }

    public function mirrorCrmAccrualToEngine(
        LoyaltyAccount $account,
        int $outletId,
        int $pointsDelta,
        string $crmIdempotencyKey,
        ?User $user = null,
    ): bool {
        if ($pointsDelta <= 0 || self::isMirrorOriginKey($crmIdempotencyKey)) {
            return false;
        }

        $member = $this->memberLoyaltyAccountLinker->findMemberByLoyaltyAccountId((int) $account->id);
        if ($member === null) {
            return false;
        }

        $engineReferenceId = self::MIRROR_CRM_PREFIX.$outletId.'-'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $crmIdempotencyKey);
        $existing = LoyaltyMemberLedger::query()
            ->where('member_id', (int) $member->id)
            ->where('type', LoyaltyMemberLedger::TYPE_ADJUSTMENT)
            ->where('reference_type', 'crm_mirror')
            ->where('reference_id', $engineReferenceId)
            ->exists();

        if ($existing) {
            return false;
        }

        $entry = $this->loyaltyLedgerService->createEntry(
            memberId: (int) $member->id,
            type: LoyaltyMemberLedger::TYPE_ADJUSTMENT,
            points: $pointsDelta,
            referenceType: 'crm_mirror',
            referenceId: $engineReferenceId,
            description: 'Mirrored CRM accrual',
        );

        $this->balanceProjectionService->applyLedgerEntry($entry, skipMirror: true);

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function writeCrmLedgerDelta(
        LoyaltyAccount $account,
        int $outletId,
        string $idempotencyKey,
        int $pointsDelta,
        string $transactionType,
        ?User $user,
        ?array $meta = null,
    ): bool {
        return (bool) DB::transaction(function () use (
            $account,
            $outletId,
            $idempotencyKey,
            $pointsDelta,
            $transactionType,
            $user,
            $meta,
        ): bool {
            $existing = LoyaltyPointsLedger::query()
                ->where('outlet_id', $outletId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                return false;
            }

            $lockedAccount = LoyaltyAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $balanceBefore = (int) $lockedAccount->points_balance;
            $balanceAfter = max(0, $balanceBefore + $pointsDelta);

            LoyaltyPointsLedger::query()->create([
                'loyalty_account_id' => (int) $lockedAccount->id,
                'outlet_id' => $outletId,
                'created_by_user_id' => $user?->id,
                'idempotency_key' => $idempotencyKey,
                'transaction_type' => $transactionType,
                'points_delta' => $pointsDelta,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'spend_amount' => 0,
                'visit_increment' => 0,
                'meta' => $meta,
                'applied_at' => now(),
            ]);

            $updates = [
                'points_balance' => $balanceAfter,
                'last_activity_at' => now(),
            ];

            if ($pointsDelta > 0) {
                $updates['lifetime_points_earned'] = (int) $lockedAccount->lifetime_points_earned + $pointsDelta;
            } else {
                $updates['lifetime_points_redeemed'] = (int) $lockedAccount->lifetime_points_redeemed + abs($pointsDelta);
            }

            $lockedAccount->update($updates);

            return true;
        });
    }
}
