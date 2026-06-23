<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;

trait RendersPendingPayslips
{
    protected function renderPendingPayslipsForRun(int $runId, int $limit = 50): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            Artisan::call('payslip:render-pending', [
                '--run' => $runId,
                '--limit' => $limit,
            ]);

            $remaining = \App\Models\Modules\HR\Domain\PayrollPayslip::query()
                ->where('payroll_run_id', $runId)
                ->where('status', \App\Models\Modules\HR\Domain\PayrollPayslip::STATUS_DRAFT)
                ->whereNull('pdf_path')
                ->count();

            if ($remaining === 0) {
                break;
            }
        }
    }
}
