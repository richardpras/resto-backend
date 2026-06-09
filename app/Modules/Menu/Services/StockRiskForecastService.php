<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Inventory\Domain\InventoryValuation;
use App\Models\User;

final class StockRiskForecastService
{
    public function __construct(
        private readonly IngredientForecastService $ingredientForecast,
        private readonly ForecastAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function forecastOutlet(
        int $outletId,
        ?string $forecastDate = null,
        ?User $actor = null,
    ): array {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $ingredientForecast = $this->ingredientForecast->forecastOutlet($outletId, $targetDate, $actor);
        $risks = [];

        foreach ($ingredientForecast['ingredients'] as $row) {
            $ingredientId = (int) $row['inventoryItemId'];
            $avgDailyUsage = (float) $row['predictedQuantity'];
            $valuation = InventoryValuation::query()
                ->where('outlet_id', $outletId)
                ->where('ingredient_id', $ingredientId)
                ->first();

            $currentStock = $valuation !== null ? (float) $valuation->stock_quantity : 0.0;
            $daysRemaining = $avgDailyUsage > 0 ? round($currentStock / $avgDailyUsage, 2) : ($currentStock > 0 ? 999.0 : 0.0);
            $riskLevel = $this->classifyRisk($daysRemaining);

            if ($riskLevel === 'low' && $currentStock > 0) {
                continue;
            }

            $risks[] = [
                'inventoryItemId' => (string) $ingredientId,
                'ingredientName' => $row['ingredientName'] ?? null,
                'currentStock' => $currentStock,
                'avgDailyUsage' => $avgDailyUsage,
                'daysRemaining' => $daysRemaining,
                'riskLevel' => $riskLevel,
                'shortageProbability' => $this->shortageProbability($daysRemaining),
                'confidenceScore' => (float) $row['confidenceScore'],
            ];
        }

        usort($risks, static fn ($a, $b) => $a['daysRemaining'] <=> $b['daysRemaining']);

        if ($risks !== []) {
            $this->auditService->log('stock_risk_detected', $outletId, $outletId, $actor, [
                'riskCount' => count($risks),
            ], entityType: 'outlet');
        }

        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'risks' => $risks,
        ];
    }

    public function classifyRisk(float $daysRemaining): string
    {
        if ($daysRemaining <= 2) {
            return 'critical';
        }
        if ($daysRemaining <= 5) {
            return 'high';
        }
        if ($daysRemaining <= 10) {
            return 'medium';
        }

        return 'low';
    }

    public function shortageProbability(float $daysRemaining): float
    {
        if ($daysRemaining <= 0) {
            return 1.0;
        }
        if ($daysRemaining <= 2) {
            return 0.9;
        }
        if ($daysRemaining <= 5) {
            return 0.7;
        }
        if ($daysRemaining <= 10) {
            return 0.4;
        }

        return 0.1;
    }
}
