<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\OvertimeDailySummaryResource;
use App\Modules\HR\Services\OvertimeSummaryService;
use Illuminate\Http\JsonResponse;

class OvertimeSummaryController extends Controller
{
    public function __construct(
        private readonly OvertimeSummaryService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'fromDate' => request()->query('fromDate'),
            'toDate' => request()->query('toDate'),
        ]);

        return response()->json([
            'data' => OvertimeDailySummaryResource::collection($rows),
        ]);
    }

    public function show(int $summary): JsonResponse
    {
        $row = $this->service->findAccessible($this->resolveUser(), $summary);

        return response()->json([
            'data' => new OvertimeDailySummaryResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
