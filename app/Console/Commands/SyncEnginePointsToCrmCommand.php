<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Modules\Members\Services\MemberPointsMirrorService;
use Illuminate\Console\Command;

class SyncEnginePointsToCrmCommand extends Command
{
    protected $signature = 'loyalty:sync-engine-to-crm {--outletId=} {--memberId=} {--dry-run : Report without writing}';

    protected $description = 'Mirror loyalty engine ledger entries to CRM points ledger (idempotent backfill)';

    public function handle(MemberPointsMirrorService $mirrorService): int
    {
        $outletId = is_numeric($this->option('outletId')) && (int) $this->option('outletId') > 0
            ? (int) $this->option('outletId')
            : null;
        $memberId = is_numeric($this->option('memberId')) && (int) $this->option('memberId') > 0
            ? (int) $this->option('memberId')
            : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no CRM writes.');
        }

        $query = LoyaltyMemberLedger::query()->orderBy('id');
        if ($memberId !== null) {
            $query->where('member_id', $memberId);
        } elseif ($outletId !== null) {
            $memberIds = Member::query()->where('outlet_id', $outletId)->pluck('id');
            $query->whereIn('member_id', $memberIds);
        }

        $synced = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($query->cursor() as $entry) {
            if ($dryRun) {
                $idempotencyKey = MemberPointsMirrorService::MIRROR_ENGINE_PREFIX.$entry->id;
                $exists = \App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->exists();
                if ($exists) {
                    $skipped++;
                } else {
                    $synced++;
                }

                continue;
            }

            try {
                $written = $mirrorService->mirrorEngineEntryToCrm($entry);
                if ($written) {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Entry {$entry->id}: {$e->getMessage()}");
            }
        }

        $this->info("Synced: {$synced}");
        $this->info("Skipped: {$skipped}");
        if ($errors > 0) {
            $this->warn("Errors: {$errors}");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
