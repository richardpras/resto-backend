<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyLedgerService
{
    /**
     * Immutable append-only ledger. Returns existing row when reference matches (idempotent earn).
     */
    public function createEntry(
        int $memberId,
        string $type,
        int $points,
        ?int $loyaltyProgramId = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
    ): LoyaltyMemberLedger {
        if ($points === 0) {
            throw ValidationException::withMessages([
                'points' => ['Ledger points must be non-zero.'],
            ]);
        }

        return DB::transaction(function () use (
            $memberId,
            $type,
            $points,
            $loyaltyProgramId,
            $referenceType,
            $referenceId,
            $description,
        ): LoyaltyMemberLedger {
            if ($referenceType !== null && $referenceId !== null) {
                $existing = LoyaltyMemberLedger::query()
                    ->where('member_id', $memberId)
                    ->where('type', $type)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof LoyaltyMemberLedger) {
                    return $existing;
                }
            }

            return LoyaltyMemberLedger::query()->create([
                'member_id' => $memberId,
                'loyalty_program_id' => $loyaltyProgramId,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'points' => $points,
                'description' => $description,
            ]);
        });
    }

    /**
     * @return array{entry: LoyaltyMemberLedger, created: bool}
     */
    public function createEarnFromOrder(
        int $memberId,
        int $loyaltyProgramId,
        int $orderId,
        int $points,
        ?string $description = null,
    ): array {
        $existing = LoyaltyMemberLedger::query()
            ->where('member_id', $memberId)
            ->where('type', LoyaltyMemberLedger::TYPE_EARN)
            ->where('reference_type', 'order')
            ->where('reference_id', (string) $orderId)
            ->first();

        if ($existing instanceof LoyaltyMemberLedger) {
            return ['entry' => $existing, 'created' => false];
        }

        $entry = $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_EARN,
            points: $points,
            loyaltyProgramId: $loyaltyProgramId,
            referenceType: 'order',
            referenceId: (string) $orderId,
            description: $description ?? 'Points earned from paid order',
        );

        return ['entry' => $entry, 'created' => true];
    }

    /**
     * @return array{entry: LoyaltyMemberLedger, created: bool}
     */
    public function createVisitReward(
        int $memberId,
        int $loyaltyProgramId,
        int $milestoneVisit,
        int $points,
        ?string $description = null,
    ): array {
        $referenceId = (string) $milestoneVisit;

        $existing = LoyaltyMemberLedger::query()
            ->where('member_id', $memberId)
            ->where('type', LoyaltyMemberLedger::TYPE_VISIT_REWARD)
            ->where('reference_type', 'visit')
            ->where('reference_id', $referenceId)
            ->first();

        if ($existing instanceof LoyaltyMemberLedger) {
            return ['entry' => $existing, 'created' => false];
        }

        $entry = $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_VISIT_REWARD,
            points: $points,
            loyaltyProgramId: $loyaltyProgramId,
            referenceType: 'visit',
            referenceId: $referenceId,
            description: $description ?? 'Visit milestone reward',
        );

        return ['entry' => $entry, 'created' => true];
    }

    /**
     * @return array{entry: LoyaltyMemberLedger, created: bool}
     */
    public function createPeriodReward(
        int $memberId,
        int $loyaltyProgramId,
        string $periodKey,
        int $points,
        ?string $description = null,
    ): array {
        $existing = LoyaltyMemberLedger::query()
            ->where('member_id', $memberId)
            ->where('loyalty_program_id', $loyaltyProgramId)
            ->where('type', LoyaltyMemberLedger::TYPE_PERIOD_REWARD)
            ->where('reference_type', 'period')
            ->where('reference_id', $periodKey)
            ->first();

        if ($existing instanceof LoyaltyMemberLedger) {
            return ['entry' => $existing, 'created' => false];
        }

        $entry = $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
            points: $points,
            loyaltyProgramId: $loyaltyProgramId,
            referenceType: 'period',
            referenceId: $periodKey,
            description: $description ?? 'Period spending reward',
        );

        return ['entry' => $entry, 'created' => true];
    }

    public function createRewardRedeem(
        int $memberId,
        int $redemptionId,
        int $pointsToRedeem,
        ?string $description = null,
    ): LoyaltyMemberLedger {
        if ($pointsToRedeem <= 0) {
            throw ValidationException::withMessages([
                'points' => ['Redemption points must be greater than zero.'],
            ]);
        }

        return $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_REWARD_REDEEM,
            points: -$pointsToRedeem,
            referenceType: 'reward_redemption',
            referenceId: (string) $redemptionId,
            description: $description ?? 'Catalog reward redemption',
        );
    }

    public function createRedeem(
        int $memberId,
        int $pointsToRedeem,
        ?string $description = null,
    ): LoyaltyMemberLedger {
        if ($pointsToRedeem <= 0) {
            throw ValidationException::withMessages([
                'points' => ['Redemption points must be greater than zero.'],
            ]);
        }

        return $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_REDEEM,
            points: -$pointsToRedeem,
            referenceType: 'redeem',
            referenceId: (string) \Illuminate\Support\Str::uuid(),
            description: $description ?? 'Manual loyalty redemption',
        );
    }

    public function createExpiredFromEarning(
        int $memberId,
        int $earningLedgerId,
        int $points,
        int $loyaltyProgramId,
        int $expiryDays,
    ): LoyaltyMemberLedger {
        if ($points <= 0) {
            throw ValidationException::withMessages([
                'points' => ['Expired points must be greater than zero.'],
            ]);
        }

        return $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_EXPIRED,
            points: -$points,
            loyaltyProgramId: $loyaltyProgramId,
            referenceType: 'expiry',
            referenceId: (string) $earningLedgerId,
            description: "Points expired after {$expiryDays} days",
        );
    }

    public function createManualAdjustment(
        int $memberId,
        int $points,
        ?string $description = null,
        ?int $loyaltyProgramId = null,
    ): LoyaltyMemberLedger {
        return $this->createEntry(
            memberId: $memberId,
            type: LoyaltyMemberLedger::TYPE_ADJUSTMENT,
            points: $points,
            loyaltyProgramId: $loyaltyProgramId,
            referenceType: 'manual',
            referenceId: (string) now()->timestamp.'-'.uniqid('', true),
            description: $description ?? 'Manual loyalty adjustment',
        );
    }
}
