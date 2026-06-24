<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\PayrollPreparationPeriodResource;
use App\Modules\HR\Http\Resources\PayrollPreparationSnapshotResource;
use App\Modules\HR\Services\PayrollPreparationPeriodService;
use App\Modules\HR\Services\PayrollPreparationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollPreparationPeriodController extends Controller
{
    public function __construct(
        private readonly PayrollPreparationPeriodService $periodService,
        private readonly PayrollPreparationService $preparationService,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->periodService->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
        ]);

        $rows->each(function ($period) {
            $period->employee_count = $this->periodService->snapshotCount($period);
        });

        return response()->json([
            'data' => PayrollPreparationPeriodResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
        ]);

        $period = $this->periodService->create($this->resolveUser(), $validated);
        $period->employee_count = 0;

        return response()->json([
            'message' => 'Payroll preparation period created with linked attendance period.',
            'data' => new PayrollPreparationPeriodResource($period),
        ], Response::HTTP_CREATED);
    }

    public function destroy(int $period): JsonResponse
    {
        $this->periodService->delete($this->resolveUser(), $period);

        return response()->json([
            'message' => 'Payroll preparation period deleted.',
        ]);
    }

    public function approve(int $period): JsonResponse
    {
        $row = $this->periodService->approve($this->resolveUser(), $period);
        $row->employee_count = $this->periodService->snapshotCount($row);

        return response()->json([
            'message' => 'Payroll preparation period approved.',
            'data' => new PayrollPreparationPeriodResource($row),
        ]);
    }

    public function lock(int $period): JsonResponse
    {
        $row = $this->periodService->lock($this->resolveUser(), $period);
        $row->employee_count = $this->periodService->snapshotCount($row);

        return response()->json([
            'message' => 'Payroll preparation period locked.',
            'data' => new PayrollPreparationPeriodResource($row),
        ]);
    }

    public function generate(int $period): JsonResponse
    {
        $snapshots = $this->periodService->generate($this->resolveUser(), $period);
        $row = $this->periodService->findAccessible($this->resolveUser(), $period);
        $row->employee_count = $snapshots->count();

        return response()->json([
            'message' => 'Payroll preparation snapshot generated.',
            'data' => new PayrollPreparationPeriodResource($row),
            'meta' => ['snapshotCount' => $snapshots->count()],
        ]);
    }

    public function snapshots(int $period): JsonResponse
    {
        $rows = $this->periodService->snapshots($this->resolveUser(), $period);

        return response()->json([
            'data' => PayrollPreparationSnapshotResource::collection($rows),
        ]);
    }

    public function summary(int $period): JsonResponse
    {
        $row = $this->periodService->findAccessible($this->resolveUser(), $period);
        $summary = $this->preparationService->periodSummary($row);

        return response()->json([
            'data' => array_merge($summary, [
                'periodId' => (int) $row->id,
                'outletId' => (int) $row->outlet_id,
                'periodStart' => $row->period_start?->toDateString(),
                'periodEnd' => $row->period_end?->toDateString(),
                'status' => $row->status,
                'generatedAt' => $row->generated_at?->toIso8601String(),
            ]),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
