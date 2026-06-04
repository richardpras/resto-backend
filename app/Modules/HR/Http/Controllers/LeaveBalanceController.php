<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\EmployeeLeaveBalanceResource;
use App\Modules\HR\Services\LeaveBalanceService;
use Illuminate\Http\JsonResponse;

class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $service,
    ) {}

    public function index(int $employee): JsonResponse
    {
        $rows = $this->service->listForEmployee($this->resolveUser(), $employee);

        return response()->json([
            'data' => EmployeeLeaveBalanceResource::collection($rows),
        ]);
    }

    public function update(int $employee): JsonResponse
    {
        $validated = request()->validate([
            'balances' => ['required', 'array', 'min:1'],
            'balances.*.leaveTypeId' => ['required', 'integer', 'exists:leave_types,id'],
            'balances.*.allocatedDays' => ['required', 'numeric', 'min:0'],
        ]);

        $rows = $this->service->updateAllocations(
            $this->resolveUser(),
            $employee,
            $validated['balances'],
        );

        return response()->json([
            'message' => 'Leave balances updated.',
            'data' => EmployeeLeaveBalanceResource::collection($rows),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
