<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PayslipService
{
    public function __construct(
        private readonly PayrollRunServiceV2 $payrollRuns,
        private readonly EmployeeMasterService $employeeMaster,
        private readonly PayslipPdfService $pdfService,
        private readonly SettingsDomainService $settings,
    ) {}

    /**
     * @return Collection<int, PayrollPayslip>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = PayrollPayslip::query()
            ->with(['employee', 'payrollPeriod', 'payrollRun'])
            ->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['payrollRunId'])) {
            $query->where('payroll_run_id', (int) $filters['payrollRunId']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['periodFrom'])) {
            $query->whereHas('payrollPeriod', fn ($q) => $q->where('period_end', '>=', (string) $filters['periodFrom']));
        }

        if (! empty($filters['periodTo'])) {
            $query->whereHas('payrollPeriod', fn ($q) => $q->where('period_start', '<=', (string) $filters['periodTo']));
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $payslipId): PayrollPayslip
    {
        $payslip = PayrollPayslip::query()
            ->with(['employee', 'payrollPeriod', 'payrollRun', 'outlet'])
            ->find($payslipId);

        abort_if($payslip === null, Response::HTTP_NOT_FOUND, 'Payslip not found.');

        $payslip->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $payslip->employee);

        return $payslip;
    }

    /**
     * @return Collection<int, PayrollPayslip>
     */
    public function generateForRun(?User $user, int $payrollRunId): Collection
    {
        $run = $this->payrollRuns->findAccessible($user, $payrollRunId);
        $run->load(['preparationPeriod', 'outlet', 'items.employee']);

        if ($run->status !== PayrollRunV2::STATUS_FINALIZED) {
            throw ValidationException::withMessages([
                'payrollRunId' => ['Payslips can only be generated from finalized payroll runs.'],
            ]);
        }

        $period = $run->preparationPeriod;
        abort_if($period === null, 422, 'Preparation period missing for this run.');

        if ($run->items->isEmpty()) {
            throw ValidationException::withMessages([
                'payrollRunId' => ['Payroll run has no line items to generate payslips.'],
            ]);
        }

        $companyName = $this->resolveCompanyName((int) $run->outlet_id);
        $outletName = (string) ($run->outlet?->name ?? '');
        $periodLabel = $period->period_start->format('Y-m-d').' — '.$period->period_end->format('Y-m-d');
        $periodYear = $period->period_start->format('Y');
        $periodMonth = $period->period_start->format('m');

        return DB::transaction(function () use ($run, $period, $companyName, $outletName, $periodLabel, $periodYear, $periodMonth) {
            $created = collect();

            foreach ($run->items as $item) {
                $payslip = $this->upsertPayslipFromItem(
                    $run,
                    $item,
                    $period->id,
                    $companyName,
                    $outletName,
                    $periodLabel,
                    $periodYear,
                    $periodMonth,
                );

                $path = $this->pdfService->renderAndStore($payslip);
                $payslip->update([
                    'pdf_path' => $path,
                    'status' => PayrollPayslip::STATUS_GENERATED,
                ]);

                $created->push($payslip->refresh()->load(['employee', 'payrollPeriod']));
            }

            return $created;
        });
    }

    public function publish(?User $user, int $payslipId): PayrollPayslip
    {
        $payslip = $this->findAccessible($user, $payslipId);

        if ($payslip->status === PayrollPayslip::STATUS_PUBLISHED) {
            return $payslip;
        }

        if (! in_array($payslip->status, [PayrollPayslip::STATUS_GENERATED, PayrollPayslip::STATUS_DRAFT], true)) {
            throw ValidationException::withMessages([
                'status' => ['Payslip must be generated before publishing.'],
            ]);
        }

        if ($payslip->pdf_path === null) {
            throw ValidationException::withMessages([
                'pdfPath' => ['PDF must be generated before publishing.'],
            ]);
        }

        $payslip->update([
            'status' => PayrollPayslip::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        return $payslip->refresh()->load(['employee', 'payrollPeriod']);
    }

    public function regenerate(?User $user, int $payslipId): PayrollPayslip
    {
        $payslip = $this->findAccessible($user, $payslipId);

        $path = $this->pdfService->renderAndStore($payslip);
        $payslip->update([
            'pdf_path' => $path,
            'status' => $payslip->status === PayrollPayslip::STATUS_PUBLISHED
                ? PayrollPayslip::STATUS_PUBLISHED
                : PayrollPayslip::STATUS_GENERATED,
        ]);

        return $payslip->refresh()->load(['employee', 'payrollPeriod']);
    }

    /**
     * @return Collection<int, PayrollPayslip>
     */
    public function forEmployee(?User $user, int $employeeId): Collection
    {
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        return PayrollPayslip::query()
            ->with(['payrollPeriod', 'payrollRun'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->get();
    }

    private function upsertPayslipFromItem(
        PayrollRunV2 $run,
        PayrollRunItemV2 $item,
        int $periodId,
        string $companyName,
        string $outletName,
        string $periodLabel,
        string $periodYear,
        string $periodMonth,
    ): PayrollPayslip {
        $employee = $item->employee;
        abort_if($employee === null, 422, 'Employee missing on payroll item.');

        $existing = PayrollPayslip::query()
            ->where('payroll_run_item_id', $item->id)
            ->first();

        $breakdown = [
            'companyName' => $companyName,
            'outletName' => $outletName,
            'periodLabel' => $periodLabel,
            'periodYear' => $periodYear,
            'periodMonth' => $periodMonth,
            'employeeNo' => $employee->employee_no,
            'employeeName' => $employee->full_name,
            'position' => $employee->position,
            'calculation' => $item->calculation_json ?? [],
            'generatedAt' => now()->toDateTimeString(),
        ];

        $data = [
            'outlet_id' => $run->outlet_id,
            'payroll_run_id' => $run->id,
            'payroll_run_item_id' => $item->id,
            'employee_id' => $employee->id,
            'payroll_period_id' => $periodId,
            'gross_salary' => (float) $item->gross_salary,
            'total_deductions' => (float) $item->total_deductions,
            'net_salary' => (float) $item->net_salary,
            'breakdown_json' => $breakdown,
            'status' => PayrollPayslip::STATUS_DRAFT,
        ];

        if ($existing !== null) {
            $existing->update($data);

            return $existing->refresh();
        }

        $payslip = PayrollPayslip::query()->create(array_merge($data, [
            'payslip_no' => 'TEMP',
        ]));

        $payslip->update([
            'payslip_no' => sprintf('PS-%s-%06d', $periodYear, (int) $payslip->id),
        ]);

        return $payslip->refresh();
    }

    private function resolveCompanyName(int $outletId): string
    {
        unset($outletId);

        try {
            $merchant = $this->settings->getMerchant();
            if (! empty($merchant['name'])) {
                return (string) $merchant['name'];
            }
        } catch (\Throwable) {
            // fallback below
        }

        return 'Restaurant ERP';
    }
}
