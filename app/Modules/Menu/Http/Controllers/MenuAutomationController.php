<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Services\AlertRuleService;
use App\Modules\Menu\Services\AutomationSchedulerService;
use App\Modules\Menu\Services\AutomationSnapshotService;
use App\Modules\Menu\Services\EscalationService;
use App\Modules\Menu\Services\MenuAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuAutomationController extends Controller
{
    public function __construct(
        private readonly MenuAutomationService $automationService,
        private readonly AlertRuleService $ruleService,
        private readonly AutomationSnapshotService $snapshotService,
        private readonly EscalationService $escalationService,
        private readonly AutomationSchedulerService $scheduler,
    ) {}

    public function alerts(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->automationService->getAlerts($outletId, $request->query('status')),
        ]);
    }

    public function openAlerts(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->automationService->getOpenAlerts($outletId),
        ]);
    }

    public function criticalAlerts(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->automationService->getCriticalAlerts($outletId),
        ]);
    }

    public function alertHistory(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->automationService->getAlertHistory($outletId),
        ]);
    }

    public function rules(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->ruleService->listRules($outletId),
        ]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $validated = $request->validate([
            'ruleName' => ['required', 'string', 'max:120'],
            'ruleType' => ['required', 'string', 'max:60'],
            'thresholdValue' => ['nullable', 'numeric'],
            'severity' => ['nullable', 'string', 'max:20'],
            'notificationChannels' => ['nullable', 'array'],
            'escalationEnabled' => ['nullable', 'boolean'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $rule = $this->ruleService->createRule($outletId, $validated, $request->user('api'));

        return response()->json(['data' => $rule], Response::HTTP_CREATED);
    }

    public function updateRule(Request $request, int $id): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $validated = $request->validate([
            'ruleName' => ['sometimes', 'string', 'max:120'],
            'ruleType' => ['sometimes', 'string', 'max:60'],
            'thresholdValue' => ['sometimes', 'numeric'],
            'severity' => ['sometimes', 'string', 'max:20'],
            'notificationChannels' => ['sometimes', 'array'],
            'escalationEnabled' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $rule = $this->ruleService->updateRule($id, $outletId, $validated, $request->user('api'));

        return response()->json(['data' => $rule]);
    }

    public function destroyRule(Request $request, int $id): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $this->ruleService->deleteRule($id, $outletId, $request->user('api'));

        return response()->json(['message' => 'Automation rule deleted.']);
    }

    public function resolveAlert(Request $request, int $id): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $alert = $this->automationService->resolveAlert($id, $outletId, $request->user('api'));

        return response()->json(['data' => $alert]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->automationService->getNotifications($outletId),
        ]);
    }

    public function dashboardSummary(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->automationService->getDashboardSummary($outletId),
        ]);
    }

    public function createSnapshot(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $result = $this->automationService->runAutomation($outletId, $request->user('api'));

        return response()->json([
            'message' => 'Automation snapshot created.',
            'data' => $result,
        ], Response::HTTP_CREATED);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->snapshotService->getSnapshots($outletId, $request->query('snapshotDate')),
        ]);
    }

    public function escalations(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->escalationService->listEscalationRules($outletId),
        ]);
    }

    public function runEscalations(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);

        return response()->json([
            'data' => $this->escalationService->runEscalations($outletId, $request->user('api')),
        ]);
    }

    private function requireOutletId(Request $request): int
    {
        $raw = $request->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }
}
