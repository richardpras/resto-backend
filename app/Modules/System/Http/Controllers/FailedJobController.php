<?php

namespace App\Modules\System\Http\Controllers;

use App\Modules\System\Http\Requests\ListFailedJobsRequest;
use App\Modules\System\Http\Resources\FailedJobResource;
use App\Modules\System\Http\Resources\FailedJobSnapshotResource;
use App\Modules\System\Services\FailedJobMonitoringService;
use App\Modules\System\Services\FailedJobSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FailedJobController
{
    public function __construct(
        private readonly FailedJobMonitoringService $monitoringService,
        private readonly FailedJobSnapshotService $snapshotService,
    ) {}

    public function index(ListFailedJobsRequest $request): JsonResponse
    {
        $paginator = $this->monitoringService->listFailures($request->validated());

        return response()->json([
            'data' => FailedJobResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'grouped' => [
                'byModule' => $this->monitoringService->groupByModule()->values(),
                'byQueue' => $this->monitoringService->groupByQueue()->values(),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => $this->monitoringService->aggregate(),
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        $start = $request->query('startDate');
        $end = $request->query('endDate');

        $trends = $this->snapshotService->trends(
            is_string($start) && $start !== '' ? \Carbon\Carbon::parse($start) : null,
            is_string($end) && $end !== '' ? \Carbon\Carbon::parse($end) : null,
        );

        return response()->json([
            'data' => FailedJobSnapshotResource::collection($trends),
        ]);
    }
}
