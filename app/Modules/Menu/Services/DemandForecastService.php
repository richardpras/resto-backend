<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class DemandForecastService
{
    private const WEIGHT_7_DAY = 0.6;

    private const WEIGHT_30_DAY = 0.4;

    public function __construct(
        private readonly ForecastAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function forecastOutlet(
        int $outletId,
        ?string $forecastDate = null,
        ?User $actor = null,
    ): array {
        $targetDate = $forecastDate ?? now()->addDay()->toDateString();
        $items = [];

        foreach ($this->menuIdsForOutlet($outletId) as $menuItemId) {
            $items[] = $this->forecastMenuItem($menuItemId, $outletId, $targetDate);
        }

        usort($items, static fn ($a, $b) => $b['predictedQuantity'] <=> $a['predictedQuantity']);

        $this->auditService->log('demand_forecast_generated', $outletId, $outletId, $actor, [
            'forecastDate' => $targetDate,
            'itemCount' => count($items),
        ], entityType: 'outlet');

        $this->auditService->log('forecast_generated', $outletId, $outletId, $actor, [
            'type' => 'demand',
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'forecastDate' => $targetDate,
            'items' => $items,
            'peakPeriods' => $this->detectPeakPeriods($outletId),
        ];
    }

    /** @return array<string,mixed> */
    public function forecastMenuItem(int $menuItemId, int $outletId, ?string $forecastDate = null): array
    {
        $target = Carbon::parse($forecastDate ?? now()->addDay()->toDateString());
        $dailyQty = $this->loadDailyQuantities($menuItemId, $outletId, 30);
        $avg7 = $this->averageForDays($dailyQty, 7);
        $avg30 = $this->averageForDays($dailyQty, 30);
        $base = round(($avg7 * self::WEIGHT_7_DAY) + ($avg30 * self::WEIGHT_30_DAY), 4);
        $seasonality = $this->seasonalityFactor($menuItemId, $outletId, $target);
        $predicted = round(max(0, $base * $seasonality), 4);
        $confidence = $this->confidenceScore(count($dailyQty));

        $menu = MenuItem::query()->find($menuItemId);

        return [
            'menuItemId' => (string) $menuItemId,
            'menuItemName' => $menu?->name,
            'forecastDate' => $target->toDateString(),
            'last7DayAverage' => $avg7,
            'last30DayAverage' => $avg30,
            'seasonalityFactor' => $seasonality,
            'predictedQuantity' => $predicted,
            'confidenceScore' => $confidence,
            'horizons' => [
                'daily' => $predicted,
                'weekly' => round($predicted * 7, 4),
                'monthly' => round($predicted * 30, 4),
            ],
        ];
    }

    /** @return array<int,float> date => qty */
    public function loadDailyQuantities(int $menuItemId, int $outletId, int $days): array
    {
        $from = now()->subDays($days)->toDateString();
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->where('order_items.item_id', (string) $menuItemId)
            ->whereDate('orders.created_at', '>=', $from)
            ->selectRaw('DATE(orders.created_at) as sale_date')
            ->selectRaw('SUM(order_items.qty) as total_qty')
            ->groupByRaw('DATE(orders.created_at)')
            ->get();

        $quantities = [];
        foreach ($rows as $row) {
            $quantities[(string) $row->sale_date] = (float) $row->total_qty;
        }

        return $quantities;
    }

    /** @param array<string,float> $dailyQty */
    public function averageForDays(array $dailyQty, int $days): float
    {
        if ($dailyQty === []) {
            return 0.0;
        }

        $slice = array_slice($dailyQty, -$days, $days, true);
        $count = max(1, count($slice));

        return round(array_sum($slice) / $count, 4);
    }

    public function seasonalityFactor(int $menuItemId, int $outletId, Carbon $targetDate): float
    {
        $weekdayAvg = $this->averageByDayType($menuItemId, $outletId, weekend: false);
        $weekendAvg = $this->averageByDayType($menuItemId, $outletId, weekend: true);
        $overall = ($weekdayAvg + $weekendAvg) / 2;

        if ($overall <= 0) {
            return 1.0;
        }

        $isWeekend = $targetDate->isWeekend();
        $patternAvg = $isWeekend ? $weekendAvg : $weekdayAvg;

        if ($patternAvg <= 0) {
            return 1.0;
        }

        return round($patternAvg / $overall, 4);
    }

    /** @return array<int,array<string,mixed>> */
    public function detectPeakPeriods(int $outletId): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->whereDate('orders.created_at', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('DAYOFWEEK(orders.created_at) as dow')
            ->selectRaw('SUM(order_items.qty) as total_qty')
            ->groupByRaw('DAYOFWEEK(orders.created_at)')
            ->orderByDesc('total_qty')
            ->get();

        return $rows->map(static fn ($row): array => [
            'dayOfWeek' => (int) $row->dow,
            'totalQuantity' => (float) $row->total_qty,
        ])->values()->all();
    }

    public function confidenceScore(int $daysWithData): float
    {
        return round(min(1.0, $daysWithData / 30) * 100, 4) / 100;
    }

    private function averageByDayType(int $menuItemId, int $outletId, bool $weekend): float
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->where('order_items.item_id', (string) $menuItemId)
            ->whereDate('orders.created_at', '>=', now()->subDays(30)->toDateString());

        if ($weekend) {
            $query->whereRaw('DAYOFWEEK(orders.created_at) IN (1, 7)');
        } else {
            $query->whereRaw('DAYOFWEEK(orders.created_at) BETWEEN 2 AND 6');
        }

        $total = (float) $query->sum('order_items.qty');
        $days = $weekend ? 8 : 22;

        return round($total / max(1, $days), 4);
    }

    /** @return array<int,int> */
    private function menuIdsForOutlet(int $outletId): array
    {
        return MenuItem::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
