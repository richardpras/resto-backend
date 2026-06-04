<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\BpjsProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BpjsProfile */
class BpjsProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $employee = $this->relationLoaded('employee') ? $this->employee : null;

        return [
            'id' => (int) $this->id,
            'employeeId' => (int) $this->employee_id,
            'bpjsKesehatanNo' => $this->bpjs_kesehatan_no,
            'bpjsTkNo' => $this->bpjs_tk_no,
            'bpjsKesehatanEnabled' => (bool) $this->bpjs_kesehatan_enabled,
            'bpjsTkEnabled' => (bool) $this->bpjs_tk_enabled,
            'bpjsSalaryBase' => $this->bpjs_salary_base !== null ? (float) $this->bpjs_salary_base : null,
            'kesehatanEmployeeRateOverride' => $this->kesehatan_employee_rate_override !== null
                ? (float) $this->kesehatan_employee_rate_override : null,
            'kesehatanCompanyRateOverride' => $this->kesehatan_company_rate_override !== null
                ? (float) $this->kesehatan_company_rate_override : null,
            'jhtEmployeeRateOverride' => $this->jht_employee_rate_override !== null
                ? (float) $this->jht_employee_rate_override : null,
            'jhtCompanyRateOverride' => $this->jht_company_rate_override !== null
                ? (float) $this->jht_company_rate_override : null,
            'jpEmployeeRateOverride' => $this->jp_employee_rate_override !== null
                ? (float) $this->jp_employee_rate_override : null,
            'jpCompanyRateOverride' => $this->jp_company_rate_override !== null
                ? (float) $this->jp_company_rate_override : null,
            'jkkCompanyRateOverride' => $this->jkk_company_rate_override !== null
                ? (float) $this->jkk_company_rate_override : null,
            'jkmCompanyRateOverride' => $this->jkm_company_rate_override !== null
                ? (float) $this->jkm_company_rate_override : null,
            'employee' => $employee ? [
                'id' => (int) $employee->id,
                'employeeNo' => $employee->employee_no,
                'fullName' => $employee->full_name,
            ] : null,
        ];
    }
}
