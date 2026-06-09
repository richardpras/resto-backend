<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\User;

final class AutomationSchedulerService
{
    public function __construct(
        private readonly AnalyticsSnapshotService $analyticsSnapshot,
        private readonly MenuEngineeringSnapshotService $engineeringSnapshot,
        private readonly MenuOptimizationSnapshotService $optimizationSnapshot,
        private readonly AlertEvaluationService $alertEvaluation,
        private readonly AutomationSnapshotService $automationSnapshot,
        private readonly FoodCostAnalyticsService $foodCostAnalytics,
        private readonly ProfitabilityAnalyticsService $profitabilityAnalytics,
        private readonly ExecutiveAnalyticsService $executiveAnalytics,
        private readonly MenuAutomationAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function runDaily(int $outletId, ?User $actor = null): array
    {
        $date = now()->toDateString();

        $this->analyticsSnapshot->createDailySnapshot($outletId, $date, $actor);
        $this->engineeringSnapshot->createSnapshot($outletId, $date, $actor);
        $optimizationRows = $this->optimizationSnapshot->createSnapshot($outletId, $date, $actor);

        $alerts = $this->alertEvaluation->evaluateAll($outletId, $actor);
        $automationSnap = $this->automationSnapshot->createSnapshot(
            $outletId,
            $date,
            $actor,
            $optimizationRows->count(),
        );

        return [
            'schedule' => 'daily',
            'outletId' => $outletId,
            'snapshotDate' => $date,
            'alertsGenerated' => $alerts->count(),
            'automationSnapshotId' => (string) $automationSnap->id,
        ];
    }

    /** @return array<string,mixed> */
    public function runWeekly(int $outletId, ?User $actor = null): array
    {
        $fromDate = now()->subWeek()->toDateString();
        $toDate = now()->toDateString();

        $foodCost = $this->foodCostAnalytics->getAverageFoodCost($outletId, $fromDate, $toDate, $actor);
        $marginTrend = $this->profitabilityAnalytics->getMarginTrend($outletId, $fromDate, $toDate, $actor);
        $performance = $this->executiveAnalytics->getExecutiveSummary($outletId, $fromDate, $toDate, $actor);

        $this->persistSummaryAlert($outletId, 'weekly_menu_performance', 'Weekly menu performance summary', $performance, $actor);
        $this->persistSummaryAlert($outletId, 'weekly_margin_erosion', 'Weekly margin erosion summary', ['trend' => $marginTrend], $actor);
        $this->persistSummaryAlert($outletId, 'weekly_food_cost', 'Weekly food cost summary', $foodCost, $actor);

        return [
            'schedule' => 'weekly',
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'summariesGenerated' => 3,
        ];
    }

    /** @return array<string,mixed> */
    public function runMonthly(int $outletId, ?User $actor = null): array
    {
        $fromDate = now()->subMonth()->toDateString();
        $toDate = now()->toDateString();

        $executive = $this->executiveAnalytics->getExecutiveSummary($outletId, $fromDate, $toDate, $actor);
        $optimization = app(MenuOptimizationService::class)->generateRecommendations($outletId, $fromDate, $toDate, $actor);

        $this->persistSummaryAlert($outletId, 'monthly_executive_summary', 'Monthly executive summary', $executive, $actor);
        $this->persistSummaryAlert($outletId, 'monthly_optimization_summary', 'Monthly optimization summary', [
            'recommendationCount' => count($optimization['recommendations'] ?? []),
            'pricingOpportunities' => count($optimization['pricing']['opportunities'] ?? []),
        ], $actor);

        return [
            'schedule' => 'monthly',
            'outletId' => $outletId,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'summariesGenerated' => 2,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function persistSummaryAlert(
        int $outletId,
        string $type,
        string $title,
        array $payload,
        ?User $actor,
    ): void {
        AutomationAlert::query()->create([
            'outlet_id' => $outletId,
            'automation_rule_id' => null,
            'alert_type' => $type,
            'severity' => 'info',
            'title' => $title,
            'description' => 'Scheduled summary generated.',
            'payload_json' => $payload,
            'status' => AutomationAlert::STATUS_RESOLVED,
            'triggered_at' => now(),
            'resolved_at' => now(),
        ]);

        $this->auditService->log('automation_alert_triggered', $outletId, $outletId, $actor, [
            'alertType' => $type,
            'scheduled' => true,
        ], entityType: 'outlet');
    }
}
