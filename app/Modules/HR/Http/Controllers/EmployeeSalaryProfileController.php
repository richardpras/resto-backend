<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\EmployeeSalaryProfileResource;
use App\Modules\HR\Services\EmployeeSalaryProfileService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeSalaryProfileController extends Controller
{
    public function __construct(
        private readonly EmployeeSalaryProfileService $profileService,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->profileService->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
        ]);

        return response()->json([
            'data' => EmployeeSalaryProfileResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'basicSalary' => ['required', 'numeric', 'min:0'],
            'defaultAllowance' => ['nullable', 'numeric', 'min:0'],
            'defaultDeduction' => ['nullable', 'numeric', 'min:0'],
            'overtimeRateType' => ['nullable', 'string', 'in:fixed_hourly,multiplier_hourly_salary'],
            'overtimeRateValue' => ['nullable', 'numeric', 'min:0'],
            'unpaidLeaveDeductionEnabled' => ['nullable', 'boolean'],
            'attendanceDeductionEnabled' => ['nullable', 'boolean'],
            'attendanceDeductionPerDay' => ['nullable', 'numeric', 'min:0'],
        ]);

        $profile = $this->profileService->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Salary profile created.',
            'data' => new EmployeeSalaryProfileResource($profile),
        ], Response::HTTP_CREATED);
    }

    public function update(int $profile): JsonResponse
    {
        $validated = request()->validate([
            'basicSalary' => ['sometimes', 'numeric', 'min:0'],
            'defaultAllowance' => ['sometimes', 'numeric', 'min:0'],
            'defaultDeduction' => ['sometimes', 'numeric', 'min:0'],
            'overtimeRateType' => ['sometimes', 'string', 'in:fixed_hourly,multiplier_hourly_salary'],
            'overtimeRateValue' => ['sometimes', 'numeric', 'min:0'],
            'unpaidLeaveDeductionEnabled' => ['sometimes', 'boolean'],
            'attendanceDeductionEnabled' => ['sometimes', 'boolean'],
            'attendanceDeductionPerDay' => ['nullable', 'numeric', 'min:0'],
        ]);

        $row = $this->profileService->update($this->resolveUser(), $profile, $validated);

        return response()->json([
            'message' => 'Salary profile updated.',
            'data' => new EmployeeSalaryProfileResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
