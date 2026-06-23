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
use Throwable;

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

        $run->assertNotClosed();

        if ($run->status !== PayrollRunV2::STATUS_FINALIZED
            && ! in_array($run->status, [
                PayrollRunV2::STATUS_PROCESSING_PAYMENT,
                PayrollRunV2::STATUS_PAID,
            ], true)) {
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

                $created->push($payslip->load(['employee', 'payrollPeriod']));
            }

            return $created;
        });
    }

    public function renderPayslipPdf(PayrollPayslip $payslip): PayrollPayslip
    {
        $wasPublished = $payslip->published_at !== null;

        $payslip->update([
            'status' => PayrollPayslip::STATUS_PROCESSING,
            'render_error' => null,
        ]);

        try {
            $path = $this->pdfService->renderAndStore($payslip->refresh());
            $payslip->update([
                'pdf_path' => $path,
                'status' => $wasPublished ? PayrollPayslip::STATUS_PUBLISHED : PayrollPayslip::STATUS_GENERATED,
                'render_error' => null,
            ]);
        } catch (Throwable $e) {
            $payslip->update([
                'status' => PayrollPayslip::STATUS_FAILED,
                'render_error' => $e->getMessage(),
            ]);
        }

        return $payslip->refresh();
    }

    /**
     * @return array{processed: int, failed: int, remaining: int}
     */
    public function renderPendingBatch(?int $runId, ?int $outletId, int $limit): array
    {
        $limit = max(1, $limit);

        $query = PayrollPayslip::query()
            ->where('status', PayrollPayslip::STATUS_DRAFT)
            ->whereNull('pdf_path')
            ->orderBy('id');

        if ($runId !== null && $runId > 0) {
            $query->where('payroll_run_id', $runId);
        }

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        $rows = $query->limit($limit)->get();

        $processed = 0;
        $failed = 0;

        foreach ($rows as $payslip) {
            $result = $this->renderPayslipPdf($payslip);
            if ($result->status === PayrollPayslip::STATUS_FAILED) {
                $failed++;
            } else {
                $processed++;
            }
            gc_collect_cycles();
        }

        $remainingQuery = PayrollPayslip::query()
            ->where('status', PayrollPayslip::STATUS_DRAFT)
            ->whereNull('pdf_path');

        if ($runId !== null && $runId > 0) {
            $remainingQuery->where('payroll_run_id', $runId);
        }

        if ($outletId !== null && $outletId > 0) {
            $remainingQuery->where('outlet_id', $outletId);
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'remaining' => $remainingQuery->count(),
        ];
    }

    public function queueRegenerate(?User $user, int $payslipId): PayrollPayslip
    {
        $payslip = $this->findAccessible($user, $payslipId);
        $payslip->loadMissing('payrollRun');

        if ($payslip->payrollRun !== null) {
            $payslip->payrollRun->assertNotClosed();
        }

        if (in_array($payslip->status, [PayrollPayslip::STATUS_DRAFT, PayrollPayslip::STATUS_PROCESSING], true)) {
            return $payslip;
        }

        $payslip->update([
            'status' => PayrollPayslip::STATUS_DRAFT,
            'pdf_path' => null,
            'render_error' => null,
        ]);

        return $payslip->refresh()->load(['employee', 'payrollPeriod']);
    }

    /**
     * @return array{
     *     payrollRunId: int,
     *     phase: string,
     *     total: int,
     *     draft: int,
     *     processing: int,
     *     generated: int,
     *     published: int,
     *     failed: int,
     *     percent: int
     * }
     */
    public function getGenerationStatus(?User $user, int $payrollRunId): array
    {
        $this->payrollRuns->findAccessible($user, $payrollRunId);

        $base = PayrollPayslip::query()->where('payroll_run_id', $payrollRunId);
        $this->employeeMaster->scopeByEmployeeOutlet($base, $user, 'employee_id');

        $total = (clone $base)->count();

        if ($total === 0) {
            return [
                'payrollRunId' => $payrollRunId,
                'phase' => 'idle',
                'total' => 0,
                'draft' => 0,
                'processing' => 0,
                'generated' => 0,
                'published' => 0,
                'failed' => 0,
                'percent' => 0,
            ];
        }

        $draft = (clone $base)->where('status', PayrollPayslip::STATUS_DRAFT)->count();
        $processing = (clone $base)->where('status', PayrollPayslip::STATUS_PROCESSING)->count();
        $generated = (clone $base)->where('status', PayrollPayslip::STATUS_GENERATED)->count();
        $published = (clone $base)->where('status', PayrollPayslip::STATUS_PUBLISHED)->count();
        $failed = (clone $base)->where('status', PayrollPayslip::STATUS_FAILED)->count();

        $done = $generated + $published + $failed;
        $percent = (int) round(($done / $total) * 100);

        $phase = 'completed';
        if ($draft > 0 || $processing > 0) {
            $phase = $processing > 0 || ($draft > 0 && $done > 0) ? 'processing' : 'queued';
        } elseif ($failed > 0 && $done < $total) {
            $phase = 'failed';
        } elseif ($failed > 0) {
            $phase = 'failed';
        }

        if ($draft > 0 && $processing === 0 && $done === 0) {
            $phase = 'queued';
        }

        if ($draft === 0 && $processing === 0 && $failed === 0) {
            $phase = 'completed';
        }

        return [
            'payrollRunId' => $payrollRunId,
            'phase' => $phase,
            'total' => $total,
            'draft' => $draft,
            'processing' => $processing,
            'generated' => $generated,
            'published' => $published,
            'failed' => $failed,
            'percent' => min(100, $percent),
        ];
    }

    public function publish(?User $user, int $payslipId): PayrollPayslip
    {
        $payslip = $this->findAccessible($user, $payslipId);

        if ($payslip->status === PayrollPayslip::STATUS_PUBLISHED) {
            return $payslip;
        }

        if ($payslip->status !== PayrollPayslip::STATUS_GENERATED) {
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
            'pdf_path' => null,
            'render_error' => null,
            'status' => PayrollPayslip::STATUS_DRAFT,
        ];

        if ($existing !== null) {
            if ($existing->status === PayrollPayslip::STATUS_PUBLISHED) {
                $data['status'] = PayrollPayslip::STATUS_DRAFT;
                $data['published_at'] = $existing->published_at;
            }

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
        } catch (Throwable) {
            // fallback below
        }

        return 'Restaurant ERP';
    }
}
