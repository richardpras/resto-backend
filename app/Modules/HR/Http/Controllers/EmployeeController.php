<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\StoreEmployeeRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Http\Resources\EmployeeScheduleResource;
use App\Modules\HR\Http\Resources\EmployeeShiftHistoryResource;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\HR\Http\Resources\AttendanceRecordResource;
use App\Modules\HR\Services\AttendanceRecordQueryService;
use App\Modules\HR\Services\EmployeeRosterService;
use App\Modules\HR\Services\EmployeeShiftAssignmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $service,
        private readonly EmployeeShiftAssignmentService $shiftAssignments,
        private readonly EmployeeRosterService $rosters,
        private readonly AttendanceRecordQueryService $attendanceRecords,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);

        return response()->json([
            'data' => EmployeeResource::collection(
                $this->service->listByTenant($this->resolveUser(), $tenantId),
            ),
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->service->create($this->resolveUser(), $request->validated());

        return response()->json([
            'message' => 'Employee created successfully.',
            'data' => new EmployeeResource($employee),
        ], Response::HTTP_CREATED);
    }

    public function show(int $employee): JsonResponse
    {
        return response()->json([
            'data' => new EmployeeResource($this->service->find($this->resolveUser(), $employee)),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, int $employee): JsonResponse
    {
        $updated = $this->service->update($this->resolveUser(), $employee, $request->validated());

        return response()->json([
            'message' => 'Employee updated successfully.',
            'data' => new EmployeeResource($updated),
        ]);
    }

    public function destroy(int $employee): JsonResponse
    {
        $this->service->delete($this->resolveUser(), $employee);

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }

    public function shiftHistory(int $employee): JsonResponse
    {
        $payload = $this->shiftAssignments->shiftHistoryForEmployee($this->resolveUser(), $employee);

        return response()->json([
            'data' => new EmployeeShiftHistoryResource($payload),
        ]);
    }

    public function schedule(int $employee): JsonResponse
    {
        $payload = $this->rosters->employeeSchedule(
            $this->resolveUser(),
            $employee,
            request()->query('weekStart'),
        );

        return response()->json([
            'data' => new EmployeeScheduleResource($payload),
        ]);
    }

    public function attendance(int $employee): JsonResponse
    {
        $limit = min(90, max(1, (int) request()->query('limit', 30)));
        $rows = $this->attendanceRecords->employeeHistory($this->resolveUser(), $employee, $limit);

        return response()->json([
            'data' => AttendanceRecordResource::collection($rows),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
