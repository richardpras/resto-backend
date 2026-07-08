<?php

namespace App\Modules\Monitoring\Services;

use App\Models\User;
use App\Modules\Loyalty\Services\CustomerAnalyticsService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardSummaryService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly MonitoringMetricsService $monitoringMetricsService,
        private readonly CustomerAnalyticsService $customerAnalyticsService,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function aggregate(User $user, array $filters): array
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $requestedOutletId = isset($filters['outletId']) ? (int) $filters['outletId'] : null;

        if ($requestedOutletId !== null && ! in_array($requestedOutletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        $scopedOutletIds = $requestedOutletId !== null ? [$requestedOutletId] : $allowedOutletIds;
        sort($scopedOutletIds);
        if ($scopedOutletIds === []) {
            return [
                'outletScope' => [
                    'requestedOutletId' => $requestedOutletId,
                    'allowedOutletIds' => [],
                ],
                'kpis' => [
                    'revenueToday' => 0.0,
                    'orderCountToday' => 0,
                    'avgOrderValue' => 0.0,
                    'customerCount' => 0,
                ],
                'hourlyOrders' => [],
                'topMenus' => [],
                'recentTransactions' => [],
                'monitoring' => $this->emptyMonitoring(),
                'crmMetrics' => $this->customerAnalyticsService->metricsForOutlets([]),
                'bestSellerOtherOutlets' => [],
            ];
        }

        $startDate = isset($filters['startDate']) && is_string($filters['startDate']) && $filters['startDate'] !== ''
            ? Carbon::parse($filters['startDate'])->startOfDay()
            : Carbon::now()->startOfDay();
        $endDate = isset($filters['endDate']) && is_string($filters['endDate']) && $filters['endDate'] !== ''
            ? Carbon::parse($filters['endDate'])->endOfDay()
            : Carbon::now()->endOfDay();

        $cacheKey = sprintf(
            'dashboard:summary:%d:%s:%s:%s:%s',
            (int) $user->id,
            $requestedOutletId ?? 0,
            implode(',', $scopedOutletIds),
            $startDate->toDateString(),
            $endDate->toDateString(),
        );
        $ttlSeconds = max(5, (int) config('monitoring.dashboard_summary_cache_seconds', 20));

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () use ($requestedOutletId, $scopedOutletIds, $allowedOutletIds, $startDate, $endDate): array {
            return $this->buildSummary($requestedOutletId, $scopedOutletIds, $allowedOutletIds, $startDate, $endDate);
        });
    }

    /**
     * @param  list<int>  $scopedOutletIds
     * @param  list<int>  $allowedOutletIds
     * @return array<string,mixed>
     */
    private function buildSummary(?int $requestedOutletId, array $scopedOutletIds, array $allowedOutletIds, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $ordersInRange = DB::table('orders')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd]);

        $orderCountToday = (int) (clone $ordersInRange)->count();
        $revenueToday = (float) (clone $ordersInRange)->sum('paid_total');
        $avgOrderValue = $orderCountToday > 0 ? round($revenueToday / $orderCountToday, 2) : 0.0;
        $customerCount = (int) DB::table('loyalty_accounts')->whereIn('outlet_id', $scopedOutletIds)->count();

        $hourlyRows = DB::table('orders')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->selectRaw("DATE_FORMAT(created_at, '%l%p') as hour_label, HOUR(created_at) as hour_sort, COUNT(*) as orders")
            ->groupBy('hour_sort', 'hour_label')
            ->orderBy('hour_sort')
            ->get();

        $hourlyOrders = $hourlyRows->map(static fn ($row): array => [
            'hour' => strtoupper((string) $row->hour_label),
            'orders' => (int) $row->orders,
        ])->values()->all();

        $topMenus = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.outlet_id', $scopedOutletIds)
            ->whereBetween('o.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('oi.name as name, SUM(oi.qty) as qty, SUM(oi.line_total) as revenue')
            ->groupBy('oi.name')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(static fn ($row): array => [
                'name' => (string) $row->name,
                'qty' => (int) round((float) $row->qty),
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();

        $recentTransactions = DB::table('orders')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(static function ($row): array {
                $status = match ((string) $row->payment_status) {
                    'paid' => 'Paid',
                    'partial' => 'Partial',
                    default => 'Unpaid',
                };

                return [
                    'id' => (string) $row->code,
                    'type' => (string) ($row->service_mode ?? $row->order_type ?? '-'),
                    'total' => (float) $row->total,
                    'status' => $status,
                    'time' => Carbon::parse((string) $row->created_at)->diffForHumans(),
                ];
            })
            ->values()
            ->all();

        $monitoring = $this->monitoringMetricsService->aggregateForOutletIds($scopedOutletIds, [
            'dateFrom' => $rangeStart->toDateString(),
            'dateTo' => $rangeEnd->toDateString(),
        ]);

        return [
            'outletScope' => [
                'requestedOutletId' => $requestedOutletId,
                'allowedOutletIds' => $scopedOutletIds,
            ],
            'kpis' => [
                'revenueToday' => $revenueToday,
                'orderCountToday' => $orderCountToday,
                'avgOrderValue' => $avgOrderValue,
                'customerCount' => $customerCount,
            ],
            'hourlyOrders' => $hourlyOrders,
            'topMenus' => $topMenus,
            'recentTransactions' => $recentTransactions,
            'monitoring' => [
                'activePosSessions' => (int) (($monitoring['activePosSessions']['count'] ?? 0)),
                'pendingKitchenTickets' => (int) (($monitoring['pendingKitchenTickets']['count'] ?? 0)),
                'paymentSuccessRate' => (float) (($monitoring['paymentRate']['successRate'] ?? 0)),
                'stalePayments' => (int) (($monitoring['stalePayments']['count'] ?? 0)),
                'qrQueue' => $monitoring['qrQueue'] ?? ['pendingConfirmation' => 0, 'expired' => 0],
                'printerQueue' => $monitoring['printerQueue'] ?? ['pending' => 0, 'failed' => 0, 'recoverable' => 0, 'deadLetter' => 0],
                'offlineResilience' => $monitoring['offlineResilience'] ?? [],
                'hardwareBridge' => $monitoring['hardwareBridge'] ?? [],
            ],
            'crmMetrics' => $monitoring['crmMetrics'] ?? $this->customerAnalyticsService->metricsForOutlets($scopedOutletIds),
            'bestSellerOtherOutlets' => $this->bestSellerOtherOutlets($requestedOutletId, $allowedOutletIds, $rangeStart, $rangeEnd),
        ];
    }

    /**
     * @param  list<int>  $allowedOutletIds
     * @return list<array{name:string,qty:int,outletName:string,trend:string}>
     */
    private function bestSellerOtherOutlets(?int $requestedOutletId, array $allowedOutletIds, Carbon $todayStart, Carbon $todayEnd): array
    {
        if ($requestedOutletId === null) {
            return [];
        }

        $otherOutletIds = array_values(array_filter($allowedOutletIds, static fn (int $id): bool => $id !== $requestedOutletId));
        if ($otherOutletIds === []) {
            return [];
        }

        $priorStart = (clone $todayStart)->subDay();
        $priorEnd = (clone $todayEnd)->subDay();

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('outlets as outlet', 'outlet.id', '=', 'o.outlet_id')
            ->whereIn('o.outlet_id', $otherOutletIds)
            ->whereBetween('o.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('oi.name as name, o.outlet_id as outlet_id, outlet.name as outlet_name, SUM(oi.qty) as qty')
            ->groupBy('oi.name', 'o.outlet_id', 'outlet.name')
            ->orderByDesc('qty')
            ->limit(8)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $priorByKey = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.outlet_id', $otherOutletIds)
            ->whereBetween('o.created_at', [$priorStart, $priorEnd])
            ->selectRaw("CONCAT(oi.name, '#', o.outlet_id) as metric_key, SUM(oi.qty) as qty")
            ->groupBy('metric_key')
            ->pluck('qty', 'metric_key');

        return $rows->map(static function ($row) use ($priorByKey): array {
            $name = (string) $row->name;
            $outletId = (int) $row->outlet_id;
            $qty = (int) round((float) $row->qty);
            $key = $name.'#'.$outletId;
            $priorQty = (int) ($priorByKey[$key] ?? 0);
            $trend = $qty > $priorQty ? 'up' : ($qty < $priorQty ? 'down' : 'flat');

            return [
                'name' => $name,
                'qty' => $qty,
                'outletName' => (string) $row->outlet_name,
                'trend' => $trend,
            ];
        })->values()->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyMonitoring(): array
    {
        return [
            'activePosSessions' => 0,
            'pendingKitchenTickets' => 0,
            'paymentSuccessRate' => 0.0,
            'stalePayments' => 0,
            'qrQueue' => ['pendingConfirmation' => 0, 'expired' => 0],
            'printerQueue' => ['pending' => 0, 'failed' => 0, 'recoverable' => 0, 'deadLetter' => 0],
            'offlineResilience' => [],
            'hardwareBridge' => [],
        ];
    }
}

