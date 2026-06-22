<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItemRecoveryEvent;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;

final class OrderRecoveryReportingService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array{
     *   pendingCount: int,
     *   refundExecutedToday: float,
     *   avgResolutionHours: float|null
     * }
     */
    public function summary(?User $user, ?int $outletId = null): array
    {
        if ($user === null) {
            return ['pendingCount' => 0, 'refundExecutedToday' => 0.0, 'avgResolutionHours' => null];
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $outletIds = $allowed === [] ? [-1] : $allowed;
        if ($outletId !== null && $outletId > 0) {
            $outletIds = in_array($outletId, $allowed, true) ? [$outletId] : [-1];
        }

        $pendingCount = (int) Order::query()
            ->whereIn('outlet_id', $outletIds)
            ->whereHas('items', fn ($q) => $q->where('recovery_status', 'recovery_pending'))
            ->count();

        $refundExecutedToday = (float) OrderItemRecoveryEvent::query()
            ->whereIn('outlet_id', $outletIds)
            ->where('event_code', 'refund_executed')
            ->whereDate('created_at', today())
            ->get(['payload'])
            ->sum(fn ($row) => (float) (is_array($row->payload) ? ($row->payload['amount'] ?? 0) : 0));

        $avgResolutionHours = $this->averageResolutionHours($outletIds);

        return [
            'pendingCount' => $pendingCount,
            'refundExecutedToday' => round($refundExecutedToday, 2),
            'avgResolutionHours' => $avgResolutionHours,
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function averageResolutionHours(array $outletIds): ?float
    {
        $rows = DB::table('order_item_recovery_events as reported')
            ->join('order_item_recovery_events as approved', function ($join): void {
                $join->on('approved.order_item_id', '=', 'reported.order_item_id')
                    ->where('approved.event_code', '=', 'recovery_approved')
                    ->whereColumn('approved.id', '>', 'reported.id');
            })
            ->where('reported.event_code', 'recovery_reported')
            ->whereIn('reported.outlet_id', $outletIds)
            ->where('reported.created_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, reported.created_at, approved.created_at)) as avg_hours')
            ->value('avg_hours');

        if ($rows === null) {
            return null;
        }

        return round((float) $rows, 1);
    }
}
