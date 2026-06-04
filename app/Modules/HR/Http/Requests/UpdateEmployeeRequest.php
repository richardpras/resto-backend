<?php

namespace App\Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = (int) $this->route('employee');

        return [
            'userId' => ['nullable', 'integer', 'exists:users,id'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'employeeNo' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_no')->ignore($employeeId)],
            'name' => ['required_without:fullName', 'nullable', 'string', 'max:255'],
            'fullName' => ['required_without:name', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'position' => ['required', 'string', 'max:150'],
            'positionId' => ['nullable', 'integer', 'exists:positions,id'],
            'departmentId' => ['nullable', 'integer', 'exists:departments,id'],
            'outletId' => ['nullable', 'integer', 'exists:outlets,id'],
            'outlet' => ['nullable', 'string', 'max:255'],
            'salaryType' => ['nullable', 'in:monthly,daily,hourly'],
            'baseSalary' => ['required', 'numeric', 'min:0'],
            'overtimeRate' => ['nullable', 'numeric', 'min:0'],
            'joinDate' => ['nullable', 'date'],
            'hireDate' => ['nullable', 'date'],
            'terminationDate' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
