<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\AttendanceDailySummaryResource;
use App\Modules\HR\Services\AttendancePayrollPreparationService;
use App\Modules\HR\Services\AttendanceReviewService;
use App\Modules\HR\Services\AttendanceSummaryQueryService;
use Illuminate\Http\JsonResponse;

class AttendanceSummaryController extends Controller
{
    public function __construct(
        private readonly AttendanceSummaryQueryService $queryService,
        private readonly AttendanceReviewService $reviewService,
        private readonly AttendancePayrollPreparationService $payrollPreparation,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->queryService->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
            'fromDate' => request()->query('fromDate'),
            'toDate' => request()->query('toDate'),
        ]);

        return response()->json([
            'data' => AttendanceDailySummaryResource::collection($rows),
        ]);
    }

    public function show(int $summary): JsonResponse
    {
        $row = $this->queryService->findAccessible($this->resolveUser(), $summary);

        return response()->json([
            'data' => new AttendanceDailySummaryResource($row),
        ]);
    }

    public function review(int $summary): JsonResponse
    {
        $validated = request()->validate([
            'reviewType' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->reviewService->submitReview($this->resolveUser(), $summary, $validated);

        return response()->json([
            'message' => 'Attendance review recorded.',
            'data' => new AttendanceDailySummaryResource($row),
        ]);
    }

    public function payrollPreparation(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['nullable', 'integer', 'exists:outlets,id'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
        ]);

        $result = $this->payrollPreparation->forPeriodWithMeta($this->resolveUser(), $validated);

        return response()->json([
            'meta' => $result['meta'],
            'data' => $result['employees'],
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
