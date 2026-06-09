<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\HR\Domain\PayrollPosting;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PayrollReconciliationService
{
    public function __construct(
        private readonly GlBalanceService $glBalanceService,
    ) {}

    /** @return array<string, mixed> */
    public function report(?User $actor, ?int $outletId): array
    {
        $runQuery = PayrollRunV2::query()->where('status', 'closed');
        if ($outletId !== null && $outletId > 0) {
            $runQuery->where('outlet_id', $outletId);
        }

        $runIds = $runQuery->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $postedRunIds = PayrollPosting::query()
            ->where('posting_status', PayrollPosting::STATUS_POSTED)
            ->whereIn('payroll_run_id', $runIds !== [] ? $runIds : [-1])
            ->pluck('payroll_run_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $subledgerExpense = 0.0;
        $subledgerSalaryPayable = 0.0;
        $subledgerPph21 = 0.0;
        $subledgerBpjs = 0.0;

        if ($postedRunIds !== []) {
            $items = PayrollRunItemV2::query()->whereIn('payroll_run_id', $postedRunIds)->get();
            foreach ($items as $item) {
                $subledgerExpense += (float) $item->gross_salary;
                $subledgerSalaryPayable += (float) $item->net_salary;
                $subledgerPph21 += (float) $item->pph21_amount;
                $subledgerBpjs += (float) $item->bpjs_kesehatan_employee
                    + (float) $item->bpjs_kesehatan_company
                    + (float) $item->bpjs_jht_employee
                    + (float) $item->bpjs_jht_company
                    + (float) $item->bpjs_jp_employee
                    + (float) $item->bpjs_jp_company
                    + (float) $item->bpjs_jkk_company
                    + (float) $item->bpjs_jkm_company;
            }
        }

        $expenseGl = $this->glBalanceService->categoryBalance('payroll_expense', ['6100', '5001'], ['expense'], $outletId);
        $salaryGl = $this->glBalanceService->categoryBalance('salary_payable', ['2150', '2100'], ['liability'], $outletId);
        $pph21Gl = $this->glBalanceService->categoryBalance('pph21_payable', ['2160'], ['liability'], $outletId);
        $bpjsGl = $this->glBalanceService->categoryBalance('bpjs_payable', ['2170'], ['liability'], $outletId);

        $lines = [
            'payrollExpense' => $this->line($expenseGl, round($subledgerExpense, 2)),
            'salaryPayable' => $this->line($salaryGl, round($subledgerSalaryPayable, 2)),
            'pph21Payable' => $this->line($pph21Gl, round($subledgerPph21, 2)),
            'bpjsPayable' => $this->line($bpjsGl, round($subledgerBpjs, 2)),
        ];

        $hasVariance = collect($lines)->contains(fn (array $row): bool => $row['status'] === 'variance');

        return array_merge($lines, [
            'postedRunCount' => count($postedRunIds),
            'status' => $hasVariance ? 'variance' : 'balanced',
        ]);
    }

    /** @return array{glBalance: float, subledger: float, difference: float, status: string} */
    private function line(float $glBalance, float $subledger): array
    {
        $difference = round($glBalance - $subledger, 2);

        return [
            'glBalance' => round($glBalance, 2),
            'subledger' => round($subledger, 2),
            'difference' => $difference,
            'status' => abs($difference) <= 0.01 ? 'balanced' : 'variance',
        ];
    }
}
