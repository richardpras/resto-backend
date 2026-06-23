<?php

namespace App\Console\Commands;

use App\Modules\HR\Services\PayslipService;
use Illuminate\Console\Command;

class RenderPendingPayslipsCommand extends Command
{
    protected $signature = 'payslip:render-pending
                            {--run= : Payroll run v2 id}
                            {--outlet= : Outlet id filter}
                            {--limit=5 : Maximum payslips to render per run}';

    protected $description = 'Render pending payslip PDFs from draft records (cron / shared-hosting friendly).';

    public function handle(PayslipService $payslipService): int
    {
        $runOption = $this->option('run');
        $outletOption = $this->option('outlet');
        $limitOption = $this->option('limit');

        $runId = is_numeric($runOption) ? (int) $runOption : null;
        $outletId = is_numeric($outletOption) ? (int) $outletOption : null;
        $limit = is_numeric($limitOption) ? (int) $limitOption : 5;

        $result = $payslipService->renderPendingBatch($runId, $outletId, $limit);

        $this->info(sprintf(
            'Payslip render: processed=%d failed=%d remaining=%d',
            $result['processed'],
            $result['failed'],
            $result['remaining'],
        ));

        return self::SUCCESS;
    }
}
