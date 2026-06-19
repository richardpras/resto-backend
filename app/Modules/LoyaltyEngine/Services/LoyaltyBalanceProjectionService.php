<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Models\User;
use App\Modules\Members\Services\MemberPointsMirrorService;
use Illuminate\Support\Facades\DB;

class LoyaltyBalanceProjectionService
{
    public function applyLedgerEntry(
        LoyaltyMemberLedger $entry,
        bool $skipMirror = false,
        ?User $user = null,
    ): MemberLoyaltyBalance {
        $balance = DB::transaction(function () use ($entry): MemberLoyaltyBalance {
            $balance = MemberLoyaltyBalance::query()
                ->where('member_id', $entry->member_id)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                return MemberLoyaltyBalance::query()->create([
                    'member_id' => $entry->member_id,
                    'current_points' => $entry->points,
                ]);
            }

            $balance->update([
                'current_points' => (int) $balance->current_points + (int) $entry->points,
            ]);

            return $balance->fresh() ?? $balance;
        });

        $previousBalance = (int) $balance->current_points - (int) $entry->points;
        $member = Member::query()->whereKey($entry->member_id)->first();
        if ($member !== null) {
            app(LoyaltyAutomationService::class)->safeProcessEvent(
                (int) $member->outlet_id,
                (int) $member->id,
                LoyaltyAutomation::TRIGGER_POINTS_MILESTONE,
                [
                    'previousBalance' => $previousBalance,
                    'currentBalance' => (int) $balance->current_points,
                ],
            );
        }

        if (! $skipMirror && $entry->reference_type !== 'crm_mirror') {
            app(MemberPointsMirrorService::class)->mirrorEngineEntryToCrm($entry, $user);
        }

        return $balance;
    }

    public function currentPointsForMember(int $memberId): int
    {
        return (int) (MemberLoyaltyBalance::query()
            ->where('member_id', $memberId)
            ->value('current_points') ?? 0);
    }

    public function rebuildForMember(int $memberId): MemberLoyaltyBalance
    {
        $total = (int) LoyaltyMemberLedger::query()
            ->where('member_id', $memberId)
            ->sum('points');

        return MemberLoyaltyBalance::query()->updateOrCreate(
            ['member_id' => $memberId],
            ['current_points' => $total],
        );
    }
}
