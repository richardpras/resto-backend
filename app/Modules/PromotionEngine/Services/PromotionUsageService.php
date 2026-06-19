<?php

namespace App\Modules\PromotionEngine\Services;

use App\Models\Modules\Orders\Domain\OrderPromotion;
use App\Models\Modules\PromotionEngine\Domain\Promotion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PromotionUsageService
{
    public function countDailyRedemptions(int $promotionId, int $outletId, ?Carbon $at = null, ?int $excludeOrderId = null): int
    {
        $at ??= now();
        $start = $at->copy()->startOfDay();
        $end = $at->copy()->endOfDay();

        $query = OrderPromotion::query()
            ->where('promotion_id', $promotionId)
            ->whereBetween('applied_at', [$start, $end])
            ->whereHas('order', fn ($builder) => $builder->where('outlet_id', $outletId));

        if ($excludeOrderId !== null) {
            $query->where('order_id', '!=', $excludeOrderId);
        }

        return $query->count();
    }

    public function hasDailyCapacity(Promotion $promotion, int $outletId, ?Carbon $at = null, ?int $excludeOrderId = null): bool
    {
        $conditions = is_array($promotion->conditions) ? $promotion->conditions : [];
        $limit = (int) ($conditions['usageLimitPerDay'] ?? 0);
        if ($limit <= 0) {
            return true;
        }

        return $this->countDailyRedemptions((int) $promotion->id, $outletId, $at, $excludeOrderId) < $limit;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, float>
     */
    public function promotionDiscountsByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('order_promotions')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(discount_amount) as total')
            ->pluck('total', 'order_id')
            ->map(static fn ($total): float => (float) $total)
            ->all();
    }
}
