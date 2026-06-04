<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoyaltyExpiryService
{
    /** @var list<string> */
    private const EXPIRABLE_EARNING_TYPES = [
        LoyaltyMemberLedger::TYPE_EARN,
        LoyaltyMemberLedger::TYPE_VISIT_REWARD,
        LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
    ];

    public function __construct(
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
        private readonly LoyaltyTierRecalculationService $loyaltyTierRecalculationService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    /**
     * @return Collection<int, LoyaltyProgram>
     */
    public function programsWithExpiryEnabled(): Collection
    {
        return LoyaltyProgram::query()
            ->where('expiry_enabled', true)
            ->where('expiry_days', '>', 0)
            ->where('is_active', true)
            ->get();
    }

    public function processAllPrograms(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $created = 0;

        foreach ($this->programsWithExpiryEnabled() as $program) {
            $created += $this->processProgram($program, $asOf);
        }

        return $created;
    }

    public function processProgram(LoyaltyProgram $program, ?Carbon $asOf = null): int
    {
        if (! $program->expiry_enabled || (int) $program->expiry_days < 1) {
            return 0;
        }

        $asOf ??= now();
        $threshold = $asOf->copy()->subDays((int) $program->expiry_days);
        $created = 0;

        $query = LoyaltyMemberLedger::query()
            ->where('loyalty_program_id', $program->id)
            ->whereIn('type', self::EXPIRABLE_EARNING_TYPES)
            ->where('points', '>', 0)
            ->where('created_at', '<=', $threshold);

        if ($program->outlet_id !== null) {
            $query->whereHas('member', function ($memberQuery) use ($program): void {
                $memberQuery->where('outlet_id', $program->outlet_id);
            });
        }

        foreach ($query->orderBy('id')->cursor() as $earning) {
            if ($this->expireEarning($earning, $program)) {
                $created++;
            }
        }

        return $created;
    }

    public function expireEarning(LoyaltyMemberLedger $earning, LoyaltyProgram $program): bool
    {
        if (! in_array($earning->type, self::EXPIRABLE_EARNING_TYPES, true) || (int) $earning->points <= 0) {
            return false;
        }

        return DB::transaction(function () use ($earning, $program): bool {
            $existing = LoyaltyMemberLedger::query()
                ->where('member_id', $earning->member_id)
                ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
                ->where('reference_type', 'expiry')
                ->where('reference_id', (string) $earning->id)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof LoyaltyMemberLedger) {
                return false;
            }

            $entry = $this->ledgerService->createExpiredFromEarning(
                memberId: (int) $earning->member_id,
                earningLedgerId: (int) $earning->id,
                points: (int) $earning->points,
                loyaltyProgramId: (int) $program->id,
                expiryDays: (int) $program->expiry_days,
            );

            $this->balanceProjectionService->applyLedgerEntry($entry);

            $member = Member::query()->find((int) $earning->member_id);
            if ($member !== null && (int) $member->outlet_id > 0) {
                $this->loyaltyTierRecalculationService->recalculateForMember(
                    (int) $member->id,
                    (int) $member->outlet_id,
                    MemberTierHistory::REASON_RECALCULATION,
                );
                $this->loyaltyNotificationService->dispatchPointsExpired(
                    (int) $member->outlet_id,
                    (int) $member->id,
                    (int) $earning->points,
                );
            }

            return true;
        });
    }
}
