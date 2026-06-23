<?php

namespace App\Console\Commands;

use App\Modules\HR\Services\AttendanceSummaryService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateAttendanceSummariesCommand extends Command
{
    protected $signature = 'attendance:generate-summaries
                            {--date= : Attendance date (Y-m-d); defaults to yesterday}
                            {--outlet= : Optional outlet id scope}';

    protected $description = 'Generate attendance daily summaries for a date (idempotent). Omit --date to process yesterday; pass --date=Y-m-d for a specific day (e.g. today).';

    public function handle(AttendanceSummaryService $summaryService): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->toDateString()
            : Carbon::yesterday()->toDateString();

        $outletId = $this->option('outlet') !== null ? (int) $this->option('outlet') : null;

        $result = $summaryService->generateForDate($date, $outletId);

        $this->info(sprintf(
            'Attendance summaries for %s: %d created, %d updated.',
            $date,
            $result['created'],
            $result['updated'],
        ));

        return self::SUCCESS;
    }
}
