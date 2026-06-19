<?php

namespace App\Console\Commands;

use App\Modules\Members\Services\MemberLoyaltyAccountLinker;
use Illuminate\Console\Command;

class LinkMemberLoyaltyAccountsCommand extends Command
{
    protected $signature = 'members:link-loyalty-accounts {--outletId=} {--dry-run : Report counts without writing}';

    protected $description = 'Link members to loyalty accounts by outlet + phone, creating accounts when missing';

    public function handle(MemberLoyaltyAccountLinker $linker): int
    {
        $outletId = $this->option('outletId');
        $parsedOutletId = is_numeric($outletId) && (int) $outletId > 0 ? (int) $outletId : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no database writes.');
        }

        $result = $linker->backfillForOutlet($parsedOutletId, $dryRun);

        $this->info(sprintf(
            'Members linked to existing accounts: %d',
            $result['linked'],
        ));
        $this->info(sprintf(
            'Loyalty accounts created: %d',
            $result['created'],
        ));
        $this->info(sprintf(
            'Skipped: %d',
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
