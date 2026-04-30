<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'adjustmentAmount' => ['nullable', 'numeric'],
            'deductionAmount' => ['nullable', 'numeric', 'min:0'],
            'adjustments' => ['nullable', 'array'],
            'cashAccountCode' => ['nullable', 'string', 'max:50'],
            'salaryExpenseAccountCode' => ['nullable', 'string', 'max:50'],
        ];
    }
}
