<?php

namespace App\Modules\ShiftClose\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ShiftClose\Http\Requests\ShiftCloseRunRequest;
use App\Modules\ShiftClose\Services\ShiftCloseEngineService;
use App\Modules\ShiftClose\Services\ShiftClosePreflightService;
use App\Modules\ShiftClose\Services\ShiftCloseReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftCloseController extends Controller
{
    public function __construct(
        private readonly ShiftClosePreflightService $preflightService,
        private readonly ShiftCloseEngineService $engineService,
        private readonly ShiftCloseReportService $reportService,
    ) {}

    public function preflight(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'posSessionId' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $this->resolveUser($request);
        $outletId = (int) $validated['outletId'];
        $tenantId = isset($validated['tenantId']) ? (int) $validated['tenantId'] : null;
        $posSessionId = isset($validated['posSessionId']) ? (int) $validated['posSessionId'] : null;

        return response()->json([
            'data' => $this->preflightService->evaluate($user, $outletId, $tenantId, $posSessionId),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $this->resolveUser($request);
        $outletId = (int) $validated['outletId'];
        $tenantId = isset($validated['tenantId']) ? (int) $validated['tenantId'] : null;

        return response()->json([
            'data' => $this->engineService->readiness($user, $outletId, $tenantId),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->engineService->history(
                (int) $validated['outletId'],
                (int) ($validated['limit'] ?? 20),
            ),
        ]);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->reportService->build($id, (int) $validated['outletId']),
        ]);
    }

    public function run(ShiftCloseRunRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $outletId = (int) $request->validated('outletId');
        $tenantId = $request->validated('tenantId');
        $tenantId = $tenantId !== null ? (int) $tenantId : null;

        $result = $this->engineService->run(
            $tenantId,
            $outletId,
            $user,
            (bool) $request->validated('confirm'),
            (bool) $request->validated('force'),
            $request->validated('actualCash') !== null ? (float) $request->validated('actualCash') : null,
            $request->validated('posSessionId') !== null ? (int) $request->validated('posSessionId') : null,
        );

        return response()->json([
            'message' => 'Shift close completed successfully.',
            'data' => $result,
        ]);
    }

    private function resolveUser(Request $request): ?User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof User ? $user : null;
    }
}
