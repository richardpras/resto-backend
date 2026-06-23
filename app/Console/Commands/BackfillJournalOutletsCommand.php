<?php

namespace App\Console\Commands;

use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillJournalOutletsCommand extends Command
{
    protected $signature = 'accounting:backfill-journal-outlets {--dry-run : Report counts without writing}';

    protected $description = 'Backfill journals.outlet from outlets.name when outlet_id is set';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Journal::query()
            ->whereNotNull('outlet_id')
            ->where('outlet_id', '>', 0)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('outlets')
                    ->whereColumn('outlets.id', 'journals.outlet_id')
                    ->where(function ($inner): void {
                        $inner->whereNull('journals.outlet')
                            ->orWhere('journals.outlet', '')
                            ->orWhere('journals.outlet', 'Main Outlet')
                            ->orWhereColumn('journals.outlet', '<>', 'outlets.name');
                    });
            })
            ->count();

        if ($dryRun) {
            $this->info(sprintf('Would update %d journal(s).', $candidates));

            return self::SUCCESS;
        }

        $updated = DB::table('journals')
            ->join('outlets', 'outlets.id', '=', 'journals.outlet_id')
            ->whereNotNull('journals.outlet_id')
            ->where('journals.outlet_id', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('journals.outlet')
                    ->orWhere('journals.outlet', '')
                    ->orWhere('journals.outlet', 'Main Outlet')
                    ->orWhereColumn('journals.outlet', '<>', 'outlets.name');
            })
            ->update([
                'journals.outlet' => DB::raw('outlets.name'),
                'journals.updated_at' => now(),
            ]);

        $this->info(sprintf('Updated %d journal(s).', $updated));

        return self::SUCCESS;
    }
}
