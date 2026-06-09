<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationRule;
use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use Illuminate\Support\Collection;

final class AlertEvaluationService
{
    public function __construct(
        private readonly AlertRuleService $ruleService,
        private readonly FoodCostAnalyticsService $foodCostAnalytics,
        private readonly ProfitabilityAnalyticsService $profitabilityAnalytics,
        private readonly MenuEngineeringTrendService $engineeringTrend,
        private readonly InventoryAnalyticsService $inventoryAnalytics,
        private readonly YieldOptimizationService $yieldOptimization,
        private readonly MenuOptimizationService $optimizationService,
        private readonly NotificationDispatchService $notificationService,
        private readonly MenuAutomationAuditService $auditService,
    ) {}

    /** @return Collection<int, AutomationAlert> */
    public function evaluateAll(int $outletId, ?User $actor = null): Collection
    {
        $this->ruleService->ensureDefaultRules($outletId);
        $alerts = collect();

        foreach ([
            fn () => $this->evaluateFoodCost($outletId, $actor),
            fn () => $this->evaluateMarginErosion($outletId, $actor),
            fn () => $this->evaluateClassificationMovements($outletId, $actor),
            fn () => $this->evaluateDeadStock($outletId, $actor),
            fn () => $this->evaluateInventoryValueSpike($outletId, $actor),
            fn () => $this->evaluateYieldLoss($outletId, $actor),
            fn () => $this->evaluateMenuRemoval($outletId, $actor),
            fn () => $this->evaluateOptimizationRecommendations($outletId, $actor),
        ] as $evaluator) {
            $alerts = $alerts->merge($evaluator());
        }

        return $alerts;
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateFoodCost(int $outletId, ?User $actor = null): Collection
    {
        $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_FOOD_COST);
        if ($rule === null) {
            return collect();
        }

        $avg = $this->foodCostAnalytics->getAverageFoodCost($outletId);
        $percent = (float) $avg['averageFoodCostPercent'];
        $threshold = (float) $rule->threshold_value;

        if ($percent <= $threshold) {
            return collect();
        }

        $alert = $this->triggerAlert(
            $outletId,
            $rule,
            AutomationRule::TYPE_FOOD_COST,
            'Food cost exceeds threshold',
            sprintf('Average food cost %.2f%% exceeds threshold %.2f%%.', $percent, $threshold),
            ['averageFoodCostPercent' => $percent, 'threshold' => $threshold],
            $actor,
        );

        return collect([$alert]);
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateMarginErosion(int $outletId, ?User $actor = null): Collection
    {
        $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_MARGIN_EROSION);
        if ($rule === null) {
            return collect();
        }

        $threshold = (float) $rule->threshold_value;
        $signals = $this->profitabilityAnalytics->detectMarginErosion($outletId, $threshold, $actor);
        $alerts = collect();

        foreach ($signals as $signal) {
            $key = 'margin_erosion:'.($signal['menuItemId'] ?? '');
            if ($this->hasOpenAlert($outletId, AutomationRule::TYPE_MARGIN_EROSION, $key)) {
                continue;
            }

            $alerts->push($this->triggerAlert(
                $outletId,
                $rule,
                AutomationRule::TYPE_MARGIN_EROSION,
                'Margin erosion detected',
                sprintf('Menu item %s margin dropped %.2f%%.', $signal['menuItemName'] ?? $signal['menuItemId'], $signal['erosionPercent']),
                array_merge($signal, ['dedupeKey' => $key]),
                $actor,
            ));
        }

        return $alerts;
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateClassificationMovements(int $outletId, ?User $actor = null): Collection
    {
        $fromDate = now()->subWeek()->toDateString();
        $toDate = now()->toDateString();
        $movements = $this->engineeringTrend->detectMovement($outletId, $fromDate, $toDate);
        $alerts = collect();

        foreach ($movements as $movement) {
            $from = (string) ($movement['classificationA'] ?? '');
            $to = (string) ($movement['classificationB'] ?? '');

            if ($from === MenuEngineeringMatrixService::STAR && $to === MenuEngineeringMatrixService::PLOWHORSE) {
                $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_STAR_TO_PLOWHORSE);
                if ($rule !== null) {
                    $key = 'star_plowhorse:'.($movement['menuItemId'] ?? '');
                    if (! $this->hasOpenAlert($outletId, AutomationRule::TYPE_STAR_TO_PLOWHORSE, $key)) {
                        $alerts->push($this->triggerAlert(
                            $outletId,
                            $rule,
                            AutomationRule::TYPE_STAR_TO_PLOWHORSE,
                            'Star became Plowhorse',
                            sprintf('Menu item %s moved from STAR to PLOWHORSE.', $movement['menuItemId']),
                            array_merge($movement, ['dedupeKey' => $key]),
                            $actor,
                        ));
                    }
                }
            }

            if ($from === MenuEngineeringMatrixService::STAR && $to === MenuEngineeringMatrixService::DOG) {
                $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_STAR_TO_DOG);
                if ($rule !== null) {
                    $key = 'star_dog:'.($movement['menuItemId'] ?? '');
                    if (! $this->hasOpenAlert($outletId, AutomationRule::TYPE_STAR_TO_DOG, $key)) {
                        $alerts->push($this->triggerAlert(
                            $outletId,
                            $rule,
                            AutomationRule::TYPE_STAR_TO_DOG,
                            'Star became Dog',
                            sprintf('Menu item %s moved from STAR to DOG.', $movement['menuItemId']),
                            array_merge($movement, ['dedupeKey' => $key]),
                            $actor,
                        ));
                    }
                }
            }
        }

        return $alerts;
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateDeadStock(int $outletId, ?User $actor = null): Collection
    {
        $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_DEAD_STOCK);
        if ($rule === null) {
            return collect();
        }

        $days = (int) $rule->threshold_value;
        $deadStock = $this->inventoryAnalytics->getDeadStockIngredients($outletId, $days, $actor);

        if ($deadStock === []) {
            return collect();
        }

        $key = 'dead_stock:'.$days;
        if ($this->hasOpenAlert($outletId, AutomationRule::TYPE_DEAD_STOCK, $key)) {
            return collect();
        }

        $alert = $this->triggerAlert(
            $outletId,
            $rule,
            AutomationRule::TYPE_DEAD_STOCK,
            'Dead stock detected',
            sprintf('%d ingredients without movement for %d days.', count($deadStock), $days),
            ['ingredients' => $deadStock, 'dedupeKey' => $key],
            $actor,
        );

        return collect([$alert]);
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateInventoryValueSpike(int $outletId, ?User $actor = null): Collection
    {
        $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_INVENTORY_VALUE_SPIKE);
        if ($rule === null) {
            return collect();
        }

        $current = MenuAnalyticsSnapshot::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('snapshot_date')
            ->first();

        $previous = MenuAnalyticsSnapshot::query()
            ->where('outlet_id', $outletId)
            ->when($current !== null, fn ($q) => $q->where('snapshot_date', '<', $current->snapshot_date))
            ->orderByDesc('snapshot_date')
            ->first();

        if ($current === null || $previous === null) {
            return collect();
        }

        $prevValue = (float) $previous->inventory_value;
        $currValue = (float) $current->inventory_value;

        if ($prevValue <= 0) {
            return collect();
        }

        $changePercent = abs(round((($currValue - $prevValue) / $prevValue) * 100, 4));
        $threshold = (float) $rule->threshold_value;

        if ($changePercent <= $threshold) {
            return collect();
        }

        $key = 'inventory_spike:'.$current->snapshot_date?->toDateString();
        if ($this->hasOpenAlert($outletId, AutomationRule::TYPE_INVENTORY_VALUE_SPIKE, $key)) {
            return collect();
        }

        $alert = $this->triggerAlert(
            $outletId,
            $rule,
            AutomationRule::TYPE_INVENTORY_VALUE_SPIKE,
            'Inventory value spike',
            sprintf('Inventory value changed %.2f%% (threshold %.2f%%).', $changePercent, $threshold),
            [
                'previousValue' => $prevValue,
                'currentValue' => $currValue,
                'changePercent' => $changePercent,
                'dedupeKey' => $key,
            ],
            $actor,
        );

        return collect([$alert]);
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateYieldLoss(int $outletId, ?User $actor = null): Collection
    {
        $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_YIELD_LOSS);
        if ($rule === null) {
            return collect();
        }

        $opportunities = $this->yieldOptimization->analyzeOutlet($outletId, $actor)['opportunities'] ?? [];
        $alerts = collect();

        foreach ($opportunities as $opp) {
            $key = 'yield_loss:'.($opp['menuItemId'] ?? '');
            if ($this->hasOpenAlert($outletId, AutomationRule::TYPE_YIELD_LOSS, $key)) {
                continue;
            }

            $alerts->push($this->triggerAlert(
                $outletId,
                $rule,
                AutomationRule::TYPE_YIELD_LOSS,
                'Yield loss detected',
                sprintf('Menu item %s has yield/waste issues.', $opp['menuItemName'] ?? $opp['menuItemId']),
                array_merge($opp, ['dedupeKey' => $key]),
                $actor,
            ));
        }

        return $alerts;
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateMenuRemoval(int $outletId, ?User $actor = null): Collection
    {
        $rule = $this->ruleService->getActiveRule($outletId, AutomationRule::TYPE_MENU_REMOVAL);
        if ($rule === null) {
            return collect();
        }

        $days = (int) $rule->threshold_value;
        $since = now()->subDays($days)->toDateString();
        $alerts = collect();

        $dogItems = MenuEngineeringSnapshot::query()
            ->where('outlet_id', $outletId)
            ->where('classification', MenuEngineeringMatrixService::DOG)
            ->whereDate('snapshot_date', '>=', $since)
            ->get()
            ->groupBy('menu_item_id');

        foreach ($dogItems as $menuItemId => $snapshots) {
            if ($snapshots->count() < 2) {
                continue;
            }

            $key = 'menu_removal:'.$menuItemId;
            if ($this->hasOpenAlert($outletId, AutomationRule::TYPE_MENU_REMOVAL, $key)) {
                continue;
            }

            $alerts->push($this->triggerAlert(
                $outletId,
                $rule,
                AutomationRule::TYPE_MENU_REMOVAL,
                'Menu removal recommended',
                sprintf('Menu item %s has been DOG for %d+ days.', $menuItemId, $days),
                ['menuItemId' => (string) $menuItemId, 'snapshotCount' => $snapshots->count(), 'dedupeKey' => $key],
                $actor,
            ));
        }

        return $alerts;
    }

    /** @return Collection<int, AutomationAlert> */
    public function evaluateOptimizationRecommendations(int $outletId, ?User $actor = null): Collection
    {
        $report = $this->optimizationService->generateRecommendations($outletId, null, null, $actor);
        $alerts = collect();

        foreach ($report['pricing']['opportunities'] ?? [] as $opp) {
            $key = 'opt_price:'.($opp['menuItemId'] ?? '');
            if ($this->hasOpenAlert($outletId, 'optimization_price', $key)) {
                continue;
            }

            $alerts->push($this->createAlert(
                $outletId,
                null,
                'optimization_price',
                'warning',
                'Pricing recommendation',
                sprintf('Price optimization available for menu item %s.', $opp['menuItemName'] ?? $opp['menuItemId']),
                ['recommendation' => $opp, 'dedupeKey' => $key],
                $actor,
            ));
        }

        foreach ($report['ingredients']['opportunities'] ?? [] as $opp) {
            $key = 'opt_ingredient:'.($opp['menuItemId'] ?? '');
            if ($this->hasOpenAlert($outletId, 'optimization_ingredient', $key)) {
                continue;
            }

            $alerts->push($this->createAlert(
                $outletId,
                null,
                'optimization_ingredient',
                'warning',
                'Ingredient optimization recommendation',
                sprintf('Ingredient cost reduction for %s.', $opp['menuItemName'] ?? $opp['menuItemId']),
                ['recommendation' => $opp, 'dedupeKey' => $key],
                $actor,
            ));
        }

        foreach ($report['yield']['opportunities'] ?? [] as $opp) {
            $key = 'opt_yield:'.($opp['menuItemId'] ?? '');
            if ($this->hasOpenAlert($outletId, 'optimization_yield', $key)) {
                continue;
            }

            $alerts->push($this->createAlert(
                $outletId,
                null,
                'optimization_yield',
                'warning',
                'Yield optimization recommendation',
                sprintf('Yield improvement for %s.', $opp['menuItemName'] ?? $opp['menuItemId']),
                ['recommendation' => $opp, 'dedupeKey' => $key],
                $actor,
            ));
        }

        return $alerts;
    }

    /** @param array<string,mixed> $payload */
    private function triggerAlert(
        int $outletId,
        AutomationRule $rule,
        string $alertType,
        string $title,
        string $description,
        array $payload,
        ?User $actor,
    ): AutomationAlert {
        $alert = $this->createAlert($outletId, (int) $rule->id, $alertType, $rule->severity, $title, $description, $payload, $actor);
        $channels = $rule->notification_channels ?? ['database'];
        $this->notificationService->dispatch($alert, $channels, $actor);

        return $alert;
    }

    /** @param array<string,mixed> $payload */
    private function createAlert(
        int $outletId,
        ?int $ruleId,
        string $alertType,
        string $severity,
        string $title,
        string $description,
        array $payload,
        ?User $actor,
    ): AutomationAlert {
        $alert = AutomationAlert::query()->create([
            'outlet_id' => $outletId,
            'automation_rule_id' => $ruleId,
            'alert_type' => $alertType,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'payload_json' => $payload,
            'status' => AutomationAlert::STATUS_OPEN,
            'triggered_at' => now(),
        ]);

        $this->auditService->log('automation_alert_triggered', (int) $alert->id, $outletId, $actor, [
            'alertType' => $alertType,
            'severity' => $severity,
        ]);

        return $alert;
    }

    private function hasOpenAlert(int $outletId, string $alertType, string $dedupeKey): bool
    {
        return AutomationAlert::query()
            ->where('outlet_id', $outletId)
            ->where('alert_type', $alertType)
            ->where('status', AutomationAlert::STATUS_OPEN)
            ->where('payload_json->dedupeKey', $dedupeKey)
            ->exists();
    }
}
