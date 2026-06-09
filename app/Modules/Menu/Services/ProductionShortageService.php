<?php

namespace App\Modules\Menu\Services;

use App\Models\User;

final class ProductionShortageService
{
    public const STATUS_BALANCED = 'Balanced';
    public const STATUS_LOW_STOCK = 'Low Stock';
    public const STATUS_SHORTAGE = 'Shortage';

    public function __construct(
        private readonly ProductionPlanningService $productionPlanningService,
        private readonly MenuProductionAuditService $auditService,
    ) {}

    /**
     * @param array<int,array{menuItemId:int,quantity:float}> $menuDemands
     * @return array<string,mixed>
     */
    public function detectShortages(int $outletId, array $menuDemands, ?User $actor = null): array
    {
        $plan = $this->productionPlanningService->generateProductionPlan($outletId, $menuDemands, $actor);

        $rows = collect($plan['requirements'])->map(function (array $row): array {
            $required = (float) $row['requiredQuantity'];
            $available = (float) $row['availableStock'];
            $shortage = (float) $row['shortageQuantity'];

            return [
                'ingredientId' => $row['ingredientId'],
                'ingredientName' => $row['ingredientName'],
                'unit' => $row['unit'],
                'requiredQuantity' => $required,
                'availableStock' => $available,
                'shortageQuantity' => $shortage,
                'status' => $this->classify($required, $available, $shortage),
            ];
        })->values()->all();

        $this->auditService->log('production_shortage_detected', $outletId, $outletId, $actor, [
            'ingredientCount' => count($rows),
            'shortageCount' => collect($rows)->where('status', self::STATUS_SHORTAGE)->count(),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'shortages' => $rows,
        ];
    }

    public function classify(float $required, float $available, float $shortage): string
    {
        if ($shortage > 0) {
            return self::STATUS_SHORTAGE;
        }

        if ($required > 0 && $available <= $required) {
            return self::STATUS_LOW_STOCK;
        }

        return self::STATUS_BALANCED;
    }
}
