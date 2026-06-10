<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\PayrollRunItemV2Resource;
use App\Modules\HR\Http\Resources\PayrollRunV2Resource;
use App\Modules\HR\Services\PayrollAdjustmentService;
use App\Modules\HR\Services\PayrollRunServiceV2;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollRunV2Controller extends Controller
{
    public function __construct(
        private readonly PayrollRunServiceV2 $runService,
        private readonly PayrollAdjustmentService $payrollAdjustments,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->runService->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
        ]);

        $rows->each(function ($run) {
            $run->item_count = $run->items()->count();
        });

        return response()->json([
            'data' => PayrollRunV2Resource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'payrollPreparationPeriodId' => ['required', 'integer', 'exists:payroll_preparation_periods,id'],
        ]);

        $run = $this->runService->create($this->resolveUser(), $validated);
        $run->item_count = 0;

        return response()->json([
            'message' => 'Payroll run created.',
            'data' => new PayrollRunV2Resource($run->load('preparationPeriod')),
        ], Response::HTTP_CREATED);
    }

    public function show(int $run): JsonResponse
    {
        $row = $this->runService->findAccessible($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function calculate(int $run): JsonResponse
    {
        $row = $this->runService->calculate($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll calculated.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function approve(int $run): JsonResponse
    {
        $row = $this->runService->approve($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll run approved.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function reject(int $run): JsonResponse
    {
        $row = $this->runService->reject($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll run rejected.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function finalize(int $run): JsonResponse
    {
        $row = $this->runService->finalize($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll run finalized.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function items(int $run): JsonResponse
    {
        $runModel = $this->runService->findAccessible($this->resolveUser(), $run);
        $rows = $this->runService->items($this->resolveUser(), $run);

        $period = $runModel->preparationPeriod;
        $categoryTotals = ['totalBonus' => 0.0, 'totalIncentive' => 0.0];
        if ($period !== null) {
            $employeeIds = $rows->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
            $categoryTotals = $this->payrollAdjustments->categoryTotalsForEmployeesInPeriod(
                $employeeIds,
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            );
        }

        return response()->json([
            'data' => PayrollRunItemV2Resource::collection($rows),
            'meta' => [
                'totalOvertimePay' => round((float) $rows->sum('overtime_pay'), 2),
                'totalUnpaidLeaveDeduction' => round((float) $rows->sum('unpaid_leave_deduction'), 2),
                'totalAttendanceDeduction' => round((float) $rows->sum('attendance_deduction'), 2),
                'totalLoanDeduction' => round((float) $rows->sum('loan_deduction'), 2),
                'totalCashAdvanceDeduction' => round((float) $rows->sum('cash_advance_deduction'), 2),
                'totalAdjustmentEarning' => round((float) $rows->sum('adjustment_earning'), 2),
                'totalAdjustmentDeduction' => round((float) $rows->sum('adjustment_deduction'), 2),
                'totalBpjsEmployeeDeduction' => round(
                    (float) $rows->sum('bpjs_kesehatan_employee')
                    + (float) $rows->sum('bpjs_jht_employee')
                    + (float) $rows->sum('bpjs_jp_employee'),
                    2,
                ),
                'totalBpjsEmployerCost' => round(
                    (float) $rows->sum('bpjs_kesehatan_company')
                    + (float) $rows->sum('bpjs_jht_company')
                    + (float) $rows->sum('bpjs_jp_company')
                    + (float) $rows->sum('bpjs_jkk_company')
                    + (float) $rows->sum('bpjs_jkm_company'),
                    2,
                ),
                'totalPph21' => round((float) $rows->sum('pph21_amount'), 2),
                'totalTaxableIncome' => round((float) $rows->sum('taxable_income'), 2),
                'totalReimbursements' => round((float) $rows->sum('reimbursement_earning'), 2),
                'totalBonus' => $categoryTotals['totalBonus'],
                'totalIncentive' => $categoryTotals['totalIncentive'],
                'totalGrossSalary' => round((float) $rows->sum('gross_salary'), 2),
                'totalNetSalary' => round((float) $rows->sum('net_salary'), 2),
            ],
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
