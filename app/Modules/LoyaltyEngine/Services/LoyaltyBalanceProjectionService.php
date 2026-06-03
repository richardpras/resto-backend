<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use Illuminate\Support\Facades\DB;

class LoyaltyBalanceProjectionService
{
    public function applyLedgerEntry(LoyaltyMemberLedger $entry): MemberLoyaltyBalance
    {
        return DB::transaction(function () use ($entry): MemberLoyaltyBalance {
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
