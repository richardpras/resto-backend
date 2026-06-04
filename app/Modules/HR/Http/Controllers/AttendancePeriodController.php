<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\AttendancePeriodLockResource;
use App\Modules\HR\Services\AttendancePeriodService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AttendancePeriodController extends Controller
{
    public function __construct(
        private readonly AttendancePeriodService $periodService,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->periodService->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
        ]);

        $rows->each(function ($period) {
            $period->employee_count = $this->periodService->employeeCountForPeriod($period);
        });

        return response()->json([
            'data' => AttendancePeriodLockResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
            'notes' => ['nullable', 'string'],
        ]);

        $period = $this->periodService->create($this->resolveUser(), $validated);
        $period->employee_count = $this->periodService->employeeCountForPeriod($period);

        return response()->json([
            'message' => 'Attendance period created.',
            'data' => new AttendancePeriodLockResource($period),
        ], Response::HTTP_CREATED);
    }

    public function approve(int $period): JsonResponse
    {
        $row = $this->periodService->approve($this->resolveUser(), $period);
        $row->employee_count = $this->periodService->employeeCountForPeriod($row);

        return response()->json([
            'message' => 'Attendance period approved.',
            'data' => new AttendancePeriodLockResource($row),
        ]);
    }

    public function lock(int $period): JsonResponse
    {
        $row = $this->periodService->lock($this->resolveUser(), $period);
        $row->employee_count = $this->periodService->employeeCountForPeriod($row);

        return response()->json([
            'message' => 'Attendance period locked.',
            'data' => new AttendancePeriodLockResource($row),
        ]);
    }

    public function reopen(int $period): JsonResponse
    {
        $row = $this->periodService->reopen($this->resolveUser(), $period);
        $row->employee_count = $this->periodService->employeeCountForPeriod($row);

        return response()->json([
            'message' => 'Attendance period reopened to draft.',
            'data' => new AttendancePeriodLockResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
