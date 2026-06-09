<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProfitabilityAnalyticsService
{
    public function __construct(
        private readonly MenuProfitabilityService $profitabilityService,
        private readonly MenuAnalyticsAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function getSummary(int $outletId, ?User $actor = null): array
    {
        $ranking = $this->getProfitabilityRanking($outletId, actor: $actor);
        $margins = collect($ranking)->pluck('marginPercent');
        $avg = $margins->isNotEmpty() ? round((float) $margins->avg(), 4) : 0.0;

        return [
            'outletId' => $outletId,
            'averageMarginPercent' => $avg,
            'menuCount' => count($ranking),
            'ranking' => $ranking,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function getTopMarginMenus(int $outletId, int $limit = 10, ?User $actor = null): array
    {
        $ranking = $this->getProfitabilityRanking($outletId, actor: $actor);
        usort($ranking, static fn (array $a, array $b): int => $b['marginPercent'] <=> $a['marginPercent']);

        return array_slice($ranking, 0, max(1, $limit));
    }

    /** @return array<int,array<string,mixed>> */
    public function getLowestMarginMenus(int $outletId, int $limit = 10, ?User $actor = null): array
    {
        $ranking = $this->getProfitabilityRanking($outletId, actor: $actor);
        usort($ranking, static fn (array $a, array $b): int => $a['marginPercent'] <=> $b['marginPercent']);

        return array_slice($ranking, 0, max(1, $limit));
    }

    /** @return array<int,array<string,mixed>> */
    public function getMarginTrend(
        int $outletId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?User $actor = null,
    ): array {
        $query = DB::table('order_item_cost_snapshots as s')
            ->join('order_items as oi', 'oi.id', '=', 's.order_item_id')
            ->where('s.outlet_id', $outletId);

        if ($fromDate) {
            $query->whereDate('s.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('s.created_at', '<=', $toDate);
        }

        $rows = $query
            ->selectRaw('DATE(s.created_at) as snapshot_date')
            ->selectRaw('SUM(oi.line_total - s.total_cost) as total_margin')
            ->selectRaw('SUM(oi.line_total) as total_revenue')
            ->groupByRaw('DATE(s.created_at)')
            ->orderBy('snapshot_date')
            ->get()
            ->map(static function ($row): array {
                $revenue = (float) $row->total_revenue;
                $margin = (float) $row->total_margin;

                return [
                    'date' => (string) $row->snapshot_date,
                    'totalMargin' => $margin,
                    'totalRevenue' => $revenue,
                    'marginPercent' => $revenue > 0 ? round(($margin / $revenue) * 100, 4) : 0.0,
                ];
            })
            ->values()
            ->all();

        $this->auditService->log('profitability_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'trend',
            'pointCount' => count($rows),
        ]);

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function getProfitabilityRanking(int $outletId, ?User $actor = null): array
    {
        $rows = [];
        foreach ($this->menuIdsForOutlet($outletId) as $menuItemId) {
            $profit = $this->profitabilityService->calculateProfitability($menuItemId, $outletId);
            $rows[] = [
                'menuItemId' => (string) $menuItemId,
                'menuItemName' => $profit['menuItemName'],
                'margin' => (float) $profit['margin'],
                'marginPercent' => (float) $profit['marginPercent'],
                'contributionMargin' => (float) $profit['contributionMargin'],
                'classification' => $profit['classification'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['contributionMargin'] <=> $a['contributionMargin']);

        $this->auditService->log('profitability_analytics_generated', $outletId, $outletId, $actor, [
            'type' => 'ranking',
            'menuCount' => count($rows),
        ]);

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function detectMarginErosion(
        int $outletId,
        float $thresholdPercent = 5.0,
        ?User $actor = null,
    ): array {
        $alerts = [];

        foreach ($this->menuIdsForOutlet($outletId) as $menuItemId) {
            $current = $this->profitabilityService->calculateProfitability($menuItemId, $outletId);
            $historical = DB::table('order_item_cost_snapshots as s')
                ->join('order_items as oi', 'oi.id', '=', 's.order_item_id')
                ->where('s.menu_item_id', $menuItemId)
                ->where('s.outlet_id', $outletId)
                ->where('oi.line_total', '>', 0)
                ->selectRaw('AVG((oi.line_total - s.total_cost) / oi.line_total * 100) as avg_margin_percent')
                ->value('avg_margin_percent');

            if ($historical === null) {
                continue;
            }

            $historicalPercent = (float) $historical;
            $currentPercent = (float) $current['marginPercent'];
            $erosion = round($historicalPercent - $currentPercent, 4);

            if ($erosion >= $thresholdPercent) {
                $alerts[] = [
                    'menuItemId' => (string) $menuItemId,
                    'menuItemName' => $current['menuItemName'],
                    'historicalMarginPercent' => round($historicalPercent, 4),
                    'currentMarginPercent' => $currentPercent,
                    'erosionPercent' => $erosion,
                ];
            }
        }

        if ($alerts !== []) {
            $this->auditService->log('margin_erosion_detected', $outletId, $outletId, $actor, [
                'alertCount' => count($alerts),
                'thresholdPercent' => $thresholdPercent,
            ]);
        }

        return $alerts;
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
