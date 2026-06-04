<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\EmployeeShiftAssignmentResource;
use App\Modules\HR\Services\EmployeeShiftAssignmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeShiftAssignmentController extends Controller
{
    public function __construct(
        private readonly EmployeeShiftAssignmentService $service,
    ) {}

    public function index(): JsonResponse
    {
        $outletId = request()->query('outletId');
        $employeeId = request()->query('employeeId');

        $rows = $this->service->list(
            $this->resolveUser(),
            $outletId !== null ? (int) $outletId : null,
            $employeeId !== null ? (int) $employeeId : null,
        );

        return response()->json([
            'data' => EmployeeShiftAssignmentResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'shiftId' => ['required', 'integer', 'exists:shifts,id'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveUntil' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'isActive' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Shift assignment created successfully.',
            'data' => new EmployeeShiftAssignmentResource($row),
        ], Response::HTTP_CREATED);
    }

    public function show(int $shiftAssignment): JsonResponse
    {
        $row = $this->service->findAccessible($this->resolveUser(), $shiftAssignment);

        return response()->json([
            'data' => new EmployeeShiftAssignmentResource($row),
        ]);
    }

    public function update(int $shiftAssignment): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['sometimes', 'integer', 'exists:employees,id'],
            'shiftId' => ['sometimes', 'integer', 'exists:shifts,id'],
            'effectiveFrom' => ['sometimes', 'date'],
            'effectiveUntil' => ['nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->update($this->resolveUser(), $shiftAssignment, $validated);

        return response()->json([
            'message' => 'Shift assignment updated successfully.',
            'data' => new EmployeeShiftAssignmentResource($row),
        ]);
    }

    public function deactivate(int $shiftAssignment): JsonResponse
    {
        $row = $this->service->deactivate($this->resolveUser(), $shiftAssignment);

        return response()->json([
            'message' => 'Shift assignment deactivated successfully.',
            'data' => new EmployeeShiftAssignmentResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
