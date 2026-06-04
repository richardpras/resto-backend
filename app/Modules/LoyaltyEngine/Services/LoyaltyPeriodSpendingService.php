<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use App\Modules\LoyaltyEngine\Support\LoyaltyPeriodWindow;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LoyaltyPeriodSpendingService
{
    public function __construct(
        private readonly LoyaltyProgramService $programService,
        private readonly LoyaltyLedgerService $ledgerService,
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
        private readonly LoyaltyTierRecalculationService $loyaltyTierRecalculationService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    /**
     * @return Collection<int, LoyaltyProgram>
     */
    public function activePeriodPrograms(): Collection
    {
        $now = now();

        return LoyaltyProgram::query()
            ->where('is_active', true)
            ->where('type', LoyaltyProgram::TYPE_PERIOD_SPENDING)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now->toDateString());
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $now->toDateString());
            })
            ->get();
    }

    public function processAllActivePrograms(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $created = 0;

        foreach ($this->activePeriodPrograms() as $program) {
            $created += $this->processProgram($program, $asOf);
        }

        return $created;
    }

    public function processProgram(LoyaltyProgram $program, ?Carbon $asOf = null): int
    {
        if ($program->type !== LoyaltyProgram::TYPE_PERIOD_SPENDING || ! $program->is_active) {
            return 0;
        }

        $asOf ??= now();
        $config = $this->programService->loadRuleConfig($program);
        $period = (string) ($config['period'] ?? '');
        if (! in_array($period, ['monthly', 'weekly', 'yearly'], true)) {
            return 0;
        }

        $window = LoyaltyPeriodWindow::forPeriod($period, $asOf);
        $outletIds = $this->resolveOutletIds($program);

        $created = 0;
        foreach ($outletIds as $outletId) {
            $memberIds = $this->memberIdsWithSpendInWindow((int) $outletId, $window['start'], $window['end']);
            foreach ($memberIds as $memberId) {
                if ($this->awardMemberForPeriod($program, (int) $memberId, $config, $window['key'], $window['start'], $window['end'])) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function awardMemberForPeriod(
        LoyaltyProgram $program,
        int $memberId,
        array $config,
        string $periodKey,
        Carbon $start,
        Carbon $end,
    ): bool {
        $totalSpend = (float) MemberTransaction::query()
            ->where('member_id', $memberId)
            ->whereBetween('transaction_at', [$start, $end])
            ->sum('total_amount');

        $points = $this->programService->calculatePeriodSpendingPoints($totalSpend, $config);
        if ($points <= 0) {
            return false;
        }

        $result = $this->ledgerService->createPeriodReward(
            memberId: $memberId,
            loyaltyProgramId: (int) $program->id,
            periodKey: $periodKey,
            points: $points,
            description: ucfirst((string) ($config['period'] ?? 'period')).' spending reward',
        );

        if ($result['created']) {
            $this->balanceProjectionService->applyLedgerEntry($result['entry']);
            $member = Member::query()->find($memberId);
            if ($member !== null && (int) $member->outlet_id > 0) {
                $this->loyaltyTierRecalculationService->recalculateForMember(
                    $memberId,
                    (int) $member->outlet_id,
                    MemberTierHistory::REASON_RECALCULATION,
                );
                $this->loyaltyNotificationService->dispatchPointsEarned(
                    (int) $member->outlet_id,
                    $memberId,
                    $points,
                );
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function resolveOutletIds(LoyaltyProgram $program): array
    {
        if ($program->outlet_id !== null) {
            return [(int) $program->outlet_id];
        }

        return Member::query()
            ->whereNotNull('outlet_id')
            ->distinct()
            ->pluck('outlet_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function memberIdsWithSpendInWindow(int $outletId, Carbon $start, Carbon $end): array
    {
        return MemberTransaction::query()
            ->whereBetween('transaction_at', [$start, $end])
            ->whereHas('member', fn ($query) => $query->where('outlet_id', $outletId))
            ->distinct()
            ->pluck('member_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
