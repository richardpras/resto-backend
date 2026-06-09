<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BundleRecommendationService
{
    public function __construct(
        private readonly MenuOptimizationAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function analyzeOutlet(int $outletId, ?string $fromDate = null, ?string $toDate = null, ?User $actor = null): array
    {
        $bundles = $this->detectBundles($outletId, $fromDate, $toDate);

        $this->auditService->log('bundle_recommendation_generated', $outletId, $outletId, $actor, [
            'bundleCount' => count($bundles),
        ], entityType: 'outlet');

        return [
            'outletId' => $outletId,
            'bundles' => $bundles,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function getTopBundles(int $outletId, int $limit = 10, ?string $fromDate = null, ?string $toDate = null): array
    {
        $bundles = $this->detectBundles($outletId, $fromDate, $toDate);

        return array_slice($bundles, 0, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    private function detectBundles(int $outletId, ?string $fromDate, ?string $toDate): array
    {
        $orderItems = $this->loadOrderItemSets($outletId, $fromDate, $toDate);
        $totalOrders = count($orderItems);

        if ($totalOrders === 0) {
            return [];
        }

        $pairCounts = [];
        $itemCounts = [];

        foreach ($orderItems as $itemIds) {
            $unique = array_values(array_unique($itemIds));
            sort($unique, SORT_NUMERIC);

            foreach ($unique as $itemId) {
                $itemCounts[$itemId] = ($itemCounts[$itemId] ?? 0) + 1;
            }

            $count = count($unique);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $key = $unique[$i].':'.$unique[$j];
                    $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                }
            }
        }

        $bundles = [];
        foreach ($pairCounts as $key => $togetherCount) {
            [$idA, $idB] = array_map('intval', explode(':', $key));
            $support = round(($togetherCount / $totalOrders) * 100, 4);
            $confidenceA = $itemCounts[$idA] > 0 ? round(($togetherCount / $itemCounts[$idA]) * 100, 4) : 0.0;
            $confidence = max($confidenceA, $itemCounts[$idB] > 0 ? round(($togetherCount / $itemCounts[$idB]) * 100, 4) : 0.0);

            if ($support < 5.0 && $confidence < 50.0) {
                continue;
            }

            $names = $this->loadMenuNames([$idA, $idB]);
            $bundles[] = [
                'menuItemIdA' => (string) $idA,
                'menuItemIdB' => (string) $idB,
                'menuItemNameA' => $names[$idA] ?? null,
                'menuItemNameB' => $names[$idB] ?? null,
                'pairLabel' => ($names[$idA] ?? 'Item '.$idA).' + '.($names[$idB] ?? 'Item '.$idB),
                'togetherCount' => $togetherCount,
                'supportPercent' => $support,
                'confidencePercent' => $confidence,
                'projectedRevenueLiftPercent' => round(min(25.0, $confidence * 0.15), 2),
            ];
        }

        usort($bundles, static fn ($a, $b) => $b['confidencePercent'] <=> $a['confidencePercent']);

        return $bundles;
    }

    /** @return array<int,array<int,int>> */
    private function loadOrderItemSets(int $outletId, ?string $fromDate, ?string $toDate): array
    {
        $query = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.outlet_id', $outletId)
            ->where('orders.payment_status', 'paid')
            ->whereNotNull('order_items.item_id');

        if ($fromDate) {
            $query->whereDate('orders.created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('orders.created_at', '<=', $toDate);
        }

        $rows = $query
            ->select('orders.id as order_id', 'order_items.item_id')
            ->get();

        $sets = [];
        foreach ($rows as $row) {
            $orderId = (int) $row->order_id;
            $itemId = (int) $row->item_id;
            $sets[$orderId][] = $itemId;
        }

        return array_values($sets);
    }

    /** @param array<int,int> $menuIds */
    /** @return array<int,string> */
    private function loadMenuNames(array $menuIds): array
    {
        return DB::table('menu_items')
            ->whereIn('id', $menuIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }
}
