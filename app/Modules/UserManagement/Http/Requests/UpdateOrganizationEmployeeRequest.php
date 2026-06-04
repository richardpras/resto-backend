<?php

namespace App\Modules\UserManagement\Http\Requests;

use App\Models\Modules\UserManagement\Domain\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employeeNo' => ['sometimes', 'string', 'max:64'],
            'fullName' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'gender' => ['nullable', 'string', 'max:32'],
            'birthDate' => ['nullable', 'date'],
            'hireDate' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([
                Employee::STATUS_ACTIVE,
                Employee::STATUS_INACTIVE,
                Employee::STATUS_RESIGNED,
                Employee::STATUS_TERMINATED,
            ])],
            'positionId' => ['nullable', 'integer', 'min:1'],
            'departmentId' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
