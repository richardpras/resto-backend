<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class IngredientForecastService
{
    public function __construct(
        private readonly DemandForecastService $demandForecast,
        private readonly RecipeVersionService $recipeVersionService,
        private readonly ForecastAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function forecastOutlet(
        int $outletId,
        ?string $forecastDate = null,
        ?User $actor = null,
    ): array {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $demand = $this->demandForecast->forecastOutlet($outletId, $targetDate);
        $ingredients = [];

        foreach ($demand['items'] as $row) {
            $menuItemId = (int) $row['menuItemId'];
            $predictedMenuDemand = (float) $row['predictedQuantity'];
            $lines = $this->recipeVersionService->getActiveRecipeLines($menuItemId);

            foreach ($lines as $line) {
                $ingredientId = (int) $line['ingredientId'];
                $recipeQty = (float) $line['quantity'];
                $predictedUsage = round($predictedMenuDemand * $recipeQty, 4);
                $key = (string) $ingredientId;

                if (! isset($ingredients[$key])) {
                    $ingredients[$key] = [
                        'inventoryItemId' => $key,
                        'ingredientName' => $line['ingredientName'] ?? null,
                        'unit' => $line['unit'] ?? null,
                        'predictedQuantity' => $predictedUsage,
                        'confidenceScore' => (float) $row['confidenceScore'],
                    ];
                } else {
                    $ingredients[$key]['predictedQuantity'] = round(
                        (float) $ingredients[$key]['predictedQuantity'] + $predictedUsage,
                        4,
                    );
                }
            }
        }

        $rows = array_values($ingredients);
        usort($rows, static fn ($a, $b) => $b['predictedQuantity'] <=> $a['predictedQuantity']);

        $this->auditService->log('ingredient_forecast_generated', $outletId, $outletId, $actor, [
            'forecastDate' => $targetDate,
            'ingredientCount' => count($rows),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'ingredients' => $rows,
        ];
    }
}
