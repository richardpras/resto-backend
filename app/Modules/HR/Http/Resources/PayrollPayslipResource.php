<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollPayslip */
class PayrollPayslipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;
        $period = $this->relationLoaded('payrollPeriod') ? $this->payrollPeriod : null;
        $run = $this->relationLoaded('payrollRun') ? $this->payrollRun : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'payrollRunId' => (int) $this->payroll_run_id,
            'payrollRunItemId' => (int) $this->payroll_run_item_id,
            'employeeId' => (int) $this->employee_id,
            'payrollPeriodId' => (int) $this->payroll_period_id,
            'payslipNo' => $this->payslip_no,
            'grossSalary' => (float) $this->gross_salary,
            'totalDeductions' => (float) $this->total_deductions,
            'netSalary' => (float) $this->net_salary,
            'pdfPath' => $this->pdf_path,
            'pdfAvailable' => $this->pdf_path !== null,
            'status' => $this->status,
            'renderError' => $this->render_error,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'breakdownJson' => $this->when($request->routeIs('*.show'), $this->breakdown_json),
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
                'position' => $employee->position,
            ] : null,
            'payrollPeriod' => $period ? [
                'id' => (int) $period->id,
                'periodStart' => $period->period_start?->toDateString(),
                'periodEnd' => $period->period_end?->toDateString(),
            ] : null,
            'payrollRun' => $run ? [
                'id' => (int) $run->id,
                'status' => $run->status,
            ] : null,
        ];
    }
}
