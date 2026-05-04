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
            'fullName' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'position' => ['required', 'string', 'max:150'],
            'baseSalary' => ['required', 'numeric', 'min:0'],
            'hireDate' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
