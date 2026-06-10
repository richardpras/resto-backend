<?php

namespace App\Modules\System\Http\Controllers;

use App\Modules\System\Http\Requests\AuditEntityHistoryRequest;
use App\Modules\System\Http\Requests\AuditSearchRequest;
use App\Modules\System\Http\Requests\ListAuditCenterRequest;
use App\Modules\System\Http\Resources\UnifiedAuditRecordResource;
use App\Modules\System\Services\AuditCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditCenterController
{
    public function __construct(
        private readonly AuditCenterService $auditCenterService,
    ) {}

    public function index(ListAuditCenterRequest $request): JsonResponse
    {
        $result = $this->auditCenterService->listTimeline($request->validated());

        return response()->json([
            'data' => UnifiedAuditRecordResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function entityHistory(AuditEntityHistoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $outletId = isset($validated['outletId']) ? (int) $validated['outletId'] : null;

        $history = $this->auditCenterService->getEntityHistory(
            (string) $validated['entityType'],
            (int) $validated['entityId'],
            $outletId,
        );

        return response()->json([
            'data' => UnifiedAuditRecordResource::collection($history),
        ]);
    }

    public function search(AuditSearchRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = (string) $validated['q'];
        unset($validated['q']);

        $result = $this->auditCenterService->search($query, $validated);

        return response()->json([
            'data' => UnifiedAuditRecordResource::collection($result['data']),
            'meta' => $result['meta'],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId');
        $parsedOutletId = is_numeric($outletId) ? (int) $outletId : null;

        $summary = $this->auditCenterService->dashboardSummary($parsedOutletId);
        $summary['riskEvents'] = UnifiedAuditRecordResource::collection($summary['riskEvents']);

        return response()->json([
            'data' => $summary,
        ]);
    }
}
