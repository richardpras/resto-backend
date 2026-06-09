<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use Carbon\Carbon;

final class PrepForecastService
{
    public function __construct(
        private readonly ProductionPlanningService $productionPlanningService,
        private readonly MenuProductionAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function forecastDaily(int $outletId, string $date, ?User $actor = null): array
    {
        return $this->forecastForRange($outletId, $date, $date, 'daily', $actor);
    }

    /** @return array<string,mixed> */
    public function forecastWeekly(int $outletId, string $weekStartDate, ?User $actor = null): array
    {
        $start = Carbon::parse($weekStartDate)->startOfDay();
        $end = $start->copy()->endOfWeek();

        return $this->forecastForRange(
            $outletId,
            $start->toDateString(),
            $end->toDateString(),
            'weekly',
            $actor,
        );
    }

    /** @return array<string,mixed> */
    public function forecastForRange(
        int $outletId,
        string $fromDate,
        string $toDate,
        string $period = 'range',
        ?User $actor = null,
    ): array {
        $menuDemands = $this->productionPlanningService->deriveMenuDemandFromOrders($outletId, $fromDate, $toDate);
        $plan = $this->productionPlanningService->generateProductionPlan($outletId, $menuDemands, $actor);

        $prepRequirements = collect($plan['requirements'])->map(static fn (array $row): array => [
            'ingredientId' => $row['ingredientId'],
            'ingredientName' => $row['ingredientName'],
            'unit' => $row['unit'],
            'prepQuantity' => $row['requiredQuantity'],
            'availableStock' => $row['availableStock'],
            'shortageQuantity' => $row['shortageQuantity'],
        ])->values()->all();

        $this->auditService->log('prep_forecast_generated', $outletId, $outletId, $actor, [
            'period' => $period,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'prepLineCount' => count($prepRequirements),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'period' => $period,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'menuDemand' => $menuDemands,
            'prepRequirements' => $prepRequirements,
            'ingredientDemand' => $plan['ingredientDemand'],
            'requirements' => $plan['requirements'],
        ];
    }
}
