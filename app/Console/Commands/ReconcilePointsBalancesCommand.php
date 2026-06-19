<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\MemberLoyaltyBalance;
use App\Modules\Members\Services\MemberPointsMirrorService;
use Illuminate\Console\Command;

class ReconcilePointsBalancesCommand extends Command
{
    protected $signature = 'loyalty:reconcile-points-balances {--outletId=} {--fix : Re-run engine-to-CRM mirror for drifted members}';

    protected $description = 'Compare engine vs CRM point balances for linked members';

    public function handle(MemberPointsMirrorService $mirrorService): int
    {
        $outletId = is_numeric($this->option('outletId')) && (int) $this->option('outletId') > 0
            ? (int) $this->option('outletId')
            : null;
        $fix = (bool) $this->option('fix');

        $query = Member::query()
            ->whereNotNull('loyalty_account_id')
            ->with(['loyaltyAccount', 'loyaltyBalance']);

        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        $driftCount = 0;
        $fixed = 0;

        foreach ($query->cursor() as $member) {
            $enginePoints = (int) ($member->loyaltyBalance?->current_points ?? 0);
            $crmPoints = (int) ($member->loyaltyAccount?->points_balance ?? 0);

            if ($enginePoints === $crmPoints) {
                continue;
            }

            $driftCount++;
            $this->line(sprintf(
                'Member #%d (%s): engine=%d crm=%d drift=%d',
                $member->id,
                $member->displayName(),
                $enginePoints,
                $crmPoints,
                $enginePoints - $crmPoints,
            ));

            if ($fix) {
                $entries = LoyaltyMemberLedger::query()
                    ->where('member_id', $member->id)
                    ->orderBy('id')
                    ->get();

                foreach ($entries as $entry) {
                    $mirrorService->mirrorEngineEntryToCrm($entry);
                }

                $member->load(['loyaltyAccount', 'loyaltyBalance']);
                $newCrm = (int) ($member->loyaltyAccount?->points_balance ?? 0);
                if ($newCrm === (int) ($member->loyaltyBalance?->current_points ?? 0)) {
                    $fixed++;
                }
            }
        }

        if ($driftCount === 0) {
            $this->info('No balance drift detected.');
        } else {
            $this->warn("Members with drift: {$driftCount}");
            if ($fix) {
                $this->info("Fixed after mirror replay: {$fixed}");
            }
        }

        return self::SUCCESS;
    }
}
