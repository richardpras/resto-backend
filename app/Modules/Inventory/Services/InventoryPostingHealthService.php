<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Inventory\Domain\InventoryIncident;
use App\Models\Modules\Orders\Domain\Order;
use Illuminate\Support\Facades\DB;

class InventoryPostingHealthService
{
    /** @return array<string, mixed> */
    public function summarize(?int $outletId): array
    {
        $queueQuery = InventoryConsumptionQueue::query();
        $incidentQuery = InventoryIncident::query()->where('status', InventoryIncident::STATUS_OPEN);

        if ($outletId !== null && $outletId > 0) {
            $queueQuery->where('outlet_id', $outletId);
            $incidentQuery->where('outlet_id', $outletId);
        }

        $pending = (clone $queueQuery)->where('status', InventoryConsumptionQueue::STATUS_PENDING)->count();
        $reviewRequired = (clone $queueQuery)->where('status', InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED)->count();
        $failed = (clone $queueQuery)->where('status', InventoryConsumptionQueue::STATUS_FAILED)->count();
        $processed = (clone $queueQuery)->where('status', InventoryConsumptionQueue::STATUS_PROCESSED)->count();
        $openIncidents = (clone $incidentQuery)->count();
        $varianceTotal = (float) (clone $incidentQuery)
            ->where('incident_type', InventoryIncident::TYPE_INSUFFICIENT_ON_POSTING)
            ->sum('variance');

        $queueTotal = $pending + $reviewRequired + $failed + $processed;
        $postingSuccessRate = $queueTotal > 0
            ? round(($processed / $queueTotal) * 100, 1)
            : 100.0;

        $pendingOrderIds = (clone $queueQuery)
            ->where('status', InventoryConsumptionQueue::STATUS_PENDING)
            ->pluck('order_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $pendingConsumptionValue = $pendingOrderIds === []
            ? 0.0
            : (float) Order::query()->whereIn('id', $pendingOrderIds)->sum('total');

        $negativeStockCount = 0;
        if ($outletId !== null && $outletId > 0) {
            $negativeStockCount = (int) DB::table('inventory_stocks')
                ->where('outlet_id', $outletId)
                ->where('stock', '<', 0)
                ->count();
        }

        $severity = 'healthy';
        if ($failed > 0 || $openIncidents > 2) {
            $severity = 'critical';
        } elseif ($reviewRequired > 0 || $pending > 5 || $openIncidents > 0) {
            $severity = 'warning';
        }

        return [
            'outletId' => $outletId,
            'pendingPostings' => $pending,
            'reviewRequiredPostings' => $reviewRequired,
            'failedPostings' => $failed,
            'processedPostings' => $processed,
            'openIncidents' => $openIncidents,
            'openVariances' => (clone $incidentQuery)
                ->where('incident_type', InventoryIncident::TYPE_INSUFFICIENT_ON_POSTING)
                ->count(),
            'stockVarianceTotal' => round($varianceTotal, 2),
            'postingSuccessRate' => $postingSuccessRate,
            'pendingConsumptionValue' => round($pendingConsumptionValue, 2),
            'negativeStockCount' => $negativeStockCount,
            'severity' => $severity,
            'enforcementMode' => app(InventorySalePolicyService::class)->getMode($outletId),
        ];
    }
}
