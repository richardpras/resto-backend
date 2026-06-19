<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\PromotionEngine\Services\PromotionUsageService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExecutiveSalesReportService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly AccountingService $accountingService,
        private readonly PromotionUsageService $promotionUsageService,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function report(User $user, array $filters, bool $includeAccountingReconciliation = false): array
    {
        [$scopedOutletIds, $start, $end] = $this->resolveScope($user, $filters);

        $orderIds = $this->qualifyingOrderIds($scopedOutletIds, $start, $end);
        $voucherDiscountsByOrder = $this->voucherDiscountsByOrder($orderIds);
        $promotionDiscountsByOrder = $this->promotionUsageService->promotionDiscountsByOrder($orderIds);
        $reservationOrderIds = $this->reservationLinkedOrderIds($orderIds);

        $orders = $orderIds === []
            ? collect()
            : DB::table('orders')
                ->whereIn('id', $orderIds)
                ->get(['id', 'outlet_id', 'subtotal', 'discount_amount', 'total', 'paid_total', 'source', 'order_channel', 'service_mode', 'created_at']);

        $giftCardSettled = $this->giftCardSettledTotals($scopedOutletIds, $start, $end, $orderIds);
        [$refundAmount, $refundCount] = $this->refundTotals($scopedOutletIds, $start, $end);

        $grossSales = 0.0;
        $promotionDiscount = 0.0;
        $voucherDiscount = 0.0;
        $loyaltyDiscount = 0.0;
        $manualDiscount = 0.0;
        $orderCount = $orders->count();
        $netSales = 0.0;

        foreach ($orders as $order) {
            $gross = (float) $order->subtotal;
            $orderVoucher = (float) ($voucherDiscountsByOrder[(int) $order->id] ?? 0);
            $orderPromotion = (float) ($promotionDiscountsByOrder[(int) $order->id] ?? 0);
            $totalDiscount = (float) ($order->discount_amount ?? 0);
            $manual = max(0, round($totalDiscount - $orderVoucher - $orderPromotion, 2));

            $grossSales += $gross;
            $voucherDiscount += $orderVoucher;
            $promotionDiscount += $orderPromotion;
            $manualDiscount += $manual;
            $netSales += max(0, round($gross - $orderVoucher - $orderPromotion - $manual, 2));
        }

        $giftCardRedemption = round($giftCardSettled['gift_card'] + $giftCardSettled['store_credit'], 2);
        $totalDiscounts = round($promotionDiscount + $voucherDiscount + $loyaltyDiscount + $manualDiscount, 2);
        $finalRevenue = round(max(0, $netSales - $refundAmount), 2);
        $avgOrderValue = $orderCount > 0 ? round($netSales / $orderCount, 2) : 0.0;

        $summary = [
            'grossSales' => round($grossSales, 2),
            'promotionDiscount' => round($promotionDiscount, 2),
            'voucherDiscount' => round($voucherDiscount, 2),
            'loyaltyDiscount' => round($loyaltyDiscount, 2),
            'manualDiscount' => round($manualDiscount, 2),
            'giftCardRedemption' => $giftCardRedemption,
            'totalDiscounts' => $totalDiscounts,
            'netSales' => round($netSales, 2),
            'refundAmount' => round($refundAmount, 2),
            'refundCount' => $refundCount,
            'finalRevenue' => $finalRevenue,
            'orderCount' => $orderCount,
            'averageOrderValue' => $avgOrderValue,
            'giftCardSalesSettled' => round($giftCardSettled['gift_card'], 2),
            'storeCreditSettled' => round($giftCardSettled['store_credit'], 2),
        ];

        $comparison = $this->buildComparison($user, $filters, $start, $end, $summary, $includeAccountingReconciliation);
        if ($comparison !== null) {
            $summary['comparison'] = $comparison;
        }

        if ($includeAccountingReconciliation) {
            $accountingRevenue = $this->accountingRevenueForPeriod(
                $scopedOutletIds,
                $start->toDateString(),
                $end->toDateString(),
            );
            $difference = round($finalRevenue - $accountingRevenue, 2);
            $summary['accountingReconciliation'] = [
                'accountingRevenue' => round($accountingRevenue, 2),
                'executiveRevenue' => $finalRevenue,
                'difference' => $difference,
                'status' => abs($difference) < 0.01 ? 'balanced' : 'variance',
            ];
        }

        return [
            'summary' => $summary,
            'trends' => $this->dailyTrends($scopedOutletIds, $start, $end),
            'channels' => $this->channelBreakdown($orders, $reservationOrderIds, $voucherDiscountsByOrder, $promotionDiscountsByOrder),
            'payments' => $this->paymentMix($scopedOutletIds, $start, $end, $orderIds, $giftCardSettled),
            'discounts' => $this->discountBreakdown($summary),
            'topProducts' => $this->topProducts($orderIds, $voucherDiscountsByOrder, $promotionDiscountsByOrder, $orders),
            'filters' => [
                'outletIds' => $scopedOutletIds,
                'startDate' => $start->toDateString(),
                'endDate' => $end->toDateString(),
                'comparisonPeriod' => $filters['comparisonPeriod'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{0:list<int>,1:Carbon,2:Carbon}
     */
    private function resolveScope(User $user, array $filters): array
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $requestedOutletId = isset($filters['outletId']) ? (int) $filters['outletId'] : null;

        if ($requestedOutletId !== null && ! in_array($requestedOutletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        $scopedOutletIds = $requestedOutletId !== null ? [$requestedOutletId] : $allowedOutletIds;
        sort($scopedOutletIds);

        $start = Carbon::parse((string) ($filters['startDate'] ?? now()->startOfMonth()->toDateString()))->startOfDay();
        $end = Carbon::parse((string) ($filters['endDate'] ?? now()->toDateString()))->endOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['endDate' => ['endDate must be on or after startDate.']]);
        }

        return [$scopedOutletIds, $start, $end];
    }

    /**
     * @param  list<int>  $scopedOutletIds
     * @return list<int>
     */
    private function qualifyingOrderIds(array $scopedOutletIds, Carbon $start, Carbon $end): array
    {
        if ($scopedOutletIds === []) {
            return [];
        }

        return DB::table('orders')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNot('status', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @param  list<int>  $orderIds @return array<int,float> */
    private function voucherDiscountsByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('order_vouchers')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->selectRaw('order_id, SUM(discount_amount) as total')
            ->pluck('total', 'order_id')
            ->map(static fn ($total): float => (float) $total)
            ->all();
    }

    /** @param  list<int>  $orderIds @return list<int> */
    private function reservationLinkedOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return DB::table('reservations')
            ->whereIn('linked_order_id', $orderIds)
            ->pluck('linked_order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $scopedOutletIds
     * @param  list<int>  $orderIds
     * @return array{gift_card: float, store_credit: float}
     */
    private function giftCardSettledTotals(array $scopedOutletIds, Carbon $start, Carbon $end, array $orderIds): array
    {
        if ($scopedOutletIds === [] || $orderIds === []) {
            return ['gift_card' => 0.0, 'store_credit' => 0.0];
        }

        $rows = DB::table('gift_card_redemption_settlements as s')
            ->join('gift_card_ledgers as l', 'l.id', '=', 's.ledger_entry_id')
            ->join('gift_card_issuances as i', 'i.id', '=', 's.issuance_id')
            ->whereIn('s.outlet_id', $scopedOutletIds)
            ->where('s.status', 'settled')
            ->whereIn('l.reference_id', array_map('strval', $orderIds))
            ->where('l.reference_type', 'order')
            ->whereBetween(DB::raw('COALESCE(s.settled_at, s.redeemed_at, s.created_at)'), [$start, $end])
            ->select(['i.instrument_type', DB::raw('SUM(s.redeemed_amount) as total')])
            ->groupBy('i.instrument_type')
            ->get();

        $giftCard = 0.0;
        $storeCredit = 0.0;
        foreach ($rows as $row) {
            if ((string) $row->instrument_type === 'store_credit') {
                $storeCredit += (float) $row->total;
            } else {
                $giftCard += (float) $row->total;
            }
        }

        return [
            'gift_card' => round($giftCard, 2),
            'store_credit' => round($storeCredit, 2),
        ];
    }

    /**
     * @param  list<int>  $scopedOutletIds
     * @return array{0: float, 1: int}
     */
    private function refundTotals(array $scopedOutletIds, Carbon $start, Carbon $end): array
    {
        if ($scopedOutletIds === []) {
            return [0.0, 0];
        }

        $txQuery = DB::table('payment_transactions')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->where('status', 'refunded')
            ->whereBetween('updated_at', [$start, $end]);

        $txAmount = (float) (clone $txQuery)->sum('amount');
        $txCount = (int) (clone $txQuery)->count();

        $journalAmount = (float) DB::table('journals as original')
            ->join('journals as reversal', 'reversal.id', '=', 'original.reversal_journal_id')
            ->join('journal_entries as je', 'je.journal_id', '=', 'reversal.id')
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->whereIn('original.outlet_id', $scopedOutletIds)
            ->where('original.source_type', 'order_payment')
            ->where('reversal.status', 'posted')
            ->where('a.type', 'revenue')
            ->whereBetween('reversal.journal_date', [$start->toDateString(), $end->toDateString()])
            ->sum('je.debit');

        $amount = round(max($txAmount, $journalAmount), 2);
        $count = max($txCount, (int) DB::table('journals as original')
            ->join('journals as reversal', 'reversal.id', '=', 'original.reversal_journal_id')
            ->whereIn('original.outlet_id', $scopedOutletIds)
            ->where('original.source_type', 'order_payment')
            ->whereBetween('reversal.journal_date', [$start->toDateString(), $end->toDateString()])
            ->count());

        return [$amount, $count];
    }

    /**
     * @param  list<int>  $scopedOutletIds
     */
    private function accountingRevenueForPeriod(array $scopedOutletIds, string $startDate, string $endDate): float
    {
        if ($scopedOutletIds === []) {
            return 0.0;
        }

        if (count($scopedOutletIds) === 1) {
            $pl = $this->accountingService->buildProfitLossReport($startDate, $endDate, $scopedOutletIds[0], null);

            return (float) ($pl['totalRevenue'] ?? 0);
        }

        $total = 0.0;
        foreach ($scopedOutletIds as $outletId) {
            $pl = $this->accountingService->buildProfitLossReport($startDate, $endDate, $outletId, null);
            $total += (float) ($pl['totalRevenue'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @param  list<int>  $scopedOutletIds
     * @return list<array{date:string,grossSales:float,netSales:float,refunds:float}>
     */
    private function dailyTrends(array $scopedOutletIds, Carbon $start, Carbon $end): array
    {
        if ($scopedOutletIds === []) {
            return [];
        }

        $orderRows = DB::table('orders')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNot('status', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("DATE(created_at) as day, SUM(subtotal) as gross_sales, SUM(GREATEST(subtotal - COALESCE(discount_amount, 0), 0)) as net_sales")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $refundRows = DB::table('payment_transactions')
            ->whereIn('outlet_id', $scopedOutletIds)
            ->where('status', 'refunded')
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('DATE(updated_at) as day, SUM(amount) as refunds')
            ->groupBy('day')
            ->pluck('refunds', 'day');

        $trends = [];
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $row = $orderRows->get($day);
            $trends[] = [
                'date' => $day,
                'grossSales' => round((float) ($row->gross_sales ?? 0), 2),
                'netSales' => round((float) ($row->net_sales ?? 0), 2),
                'refunds' => round((float) ($refundRows[$day] ?? 0), 2),
            ];
            $cursor->addDay();
        }

        return $trends;
    }

    /**
     * @param  array<int,float>  $voucherDiscountsByOrder
     * @param  array<int,float>  $promotionDiscountsByOrder
     */
    private function netSalesForOrder(object $order, array $voucherDiscountsByOrder, array $promotionDiscountsByOrder): float
    {
        $subtotal = (float) $order->subtotal;
        $voucher = (float) ($voucherDiscountsByOrder[(int) $order->id] ?? 0);
        $promotion = (float) ($promotionDiscountsByOrder[(int) $order->id] ?? 0);
        $columnDiscount = (float) ($order->discount_amount ?? 0);
        $manual = max(0, round($columnDiscount - $voucher - $promotion, 2));

        return max(0, round($subtotal - $voucher - $promotion - $manual, 2));
    }

    /**
     * @param  \Illuminate\Support\Collection<int,object>  $orders
     * @param  list<int>  $reservationOrderIds
     * @param  array<int,float>  $voucherDiscountsByOrder
     * @param  array<int,float>  $promotionDiscountsByOrder
     * @return list<array{channel:string,sales:float,orders:int,averageOrderValue:float}>
     */
    private function channelBreakdown($orders, array $reservationOrderIds, array $voucherDiscountsByOrder, array $promotionDiscountsByOrder): array
    {
        $buckets = [
            'pos' => ['sales' => 0.0, 'orders' => 0],
            'qr_ordering' => ['sales' => 0.0, 'orders' => 0],
            'reservation_deposit' => ['sales' => 0.0, 'orders' => 0],
            'online_order' => ['sales' => 0.0, 'orders' => 0],
            'walk_in' => ['sales' => 0.0, 'orders' => 0],
        ];

        foreach ($orders as $order) {
            $channel = $this->resolveChannel($order, $reservationOrderIds);
            $net = $this->netSalesForOrder($order, $voucherDiscountsByOrder, $promotionDiscountsByOrder);
            $buckets[$channel]['sales'] += $net;
            $buckets[$channel]['orders']++;
        }

        return collect($buckets)->map(function (array $bucket, string $channel): array {
            $orders = (int) $bucket['orders'];

            return [
                'channel' => $channel,
                'sales' => round((float) $bucket['sales'], 2),
                'orders' => $orders,
                'averageOrderValue' => $orders > 0 ? round($bucket['sales'] / $orders, 2) : 0.0,
            ];
        })->values()->all();
    }

    /** @param  list<int>  $reservationOrderIds */
    private function resolveChannel(object $order, array $reservationOrderIds): string
    {
        $orderId = (int) $order->id;
        if (in_array($orderId, $reservationOrderIds, true)) {
            return 'reservation_deposit';
        }

        $source = strtolower((string) ($order->source ?? ''));
        $channel = strtolower((string) ($order->order_channel ?? ''));

        if ($source === 'qr' || $channel === 'qr') {
            return 'qr_ordering';
        }
        if ($channel === 'online' || $source === 'online') {
            return 'online_order';
        }
        if ($channel === 'takeaway' || (string) ($order->service_mode ?? '') === 'takeaway') {
            return 'walk_in';
        }

        return 'pos';
    }

    /**
     * @param  list<int>  $scopedOutletIds
     * @param  list<int>  $orderIds
     * @param  array{gift_card: float, store_credit: float}  $giftCardSettled
     * @return list<array{method:string,amount:float,percentage:float,transactionCount:int}>
     */
    private function paymentMix(array $scopedOutletIds, Carbon $start, Carbon $end, array $orderIds, array $giftCardSettled): array
    {
        $totals = [
            'cash' => ['amount' => 0.0, 'count' => 0],
            'qris' => ['amount' => 0.0, 'count' => 0],
            'e_wallet' => ['amount' => 0.0, 'count' => 0],
            'credit_card' => ['amount' => 0.0, 'count' => 0],
            'debit_card' => ['amount' => 0.0, 'count' => 0],
            'gift_card' => ['amount' => $giftCardSettled['gift_card'], 'count' => 0],
            'store_credit' => ['amount' => $giftCardSettled['store_credit'], 'count' => 0],
        ];

        if ($orderIds !== []) {
            $payments = DB::table('payments')
                ->whereIn('order_id', $orderIds)
                ->whereBetween('paid_at', [$start, $end])
                ->select(['method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt')])
                ->groupBy('method')
                ->get();

            foreach ($payments as $payment) {
                $bucket = $this->mapPaymentMethod((string) $payment->method);
                $totals[$bucket]['amount'] += (float) $payment->total;
                $totals[$bucket]['count'] += (int) $payment->cnt;
            }
        }

        if ($giftCardSettled['gift_card'] > 0) {
            $totals['gift_card']['count'] = (int) DB::table('gift_card_redemption_settlements')
                ->whereIn('outlet_id', $scopedOutletIds)
                ->where('status', 'settled')
                ->whereBetween(DB::raw('COALESCE(settled_at, redeemed_at, created_at)'), [$start, $end])
                ->count();
        }
        if ($giftCardSettled['store_credit'] > 0) {
            $totals['store_credit']['count'] = (int) DB::table('gift_card_redemption_settlements as s')
                ->join('gift_card_issuances as i', 'i.id', '=', 's.issuance_id')
                ->whereIn('s.outlet_id', $scopedOutletIds)
                ->where('s.status', 'settled')
                ->where('i.instrument_type', 'store_credit')
                ->whereBetween(DB::raw('COALESCE(s.settled_at, s.redeemed_at, s.created_at)'), [$start, $end])
                ->count();
        }

        $grandTotal = array_reduce($totals, static fn (float $sum, array $row): float => $sum + (float) $row['amount'], 0.0);

        return collect($totals)->map(function (array $row, string $method) use ($grandTotal): array {
            $amount = round((float) $row['amount'], 2);

            return [
                'method' => $method,
                'amount' => $amount,
                'percentage' => $grandTotal > 0 ? round(($amount / $grandTotal) * 100, 2) : 0.0,
                'transactionCount' => (int) $row['count'],
            ];
        })->filter(static fn (array $row): bool => $row['amount'] > 0 || $row['transactionCount'] > 0)
            ->values()
            ->all();
    }

    private function mapPaymentMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'cash' => 'cash',
            'qris', 'qr', 'qr code' => 'qris',
            'e-wallet', 'ewallet', 'transfer' => 'e_wallet',
            'debit card', 'debit' => 'debit_card',
            'card', 'credit card', 'credit' => 'credit_card',
            'gift_card', 'gift card' => 'gift_card',
            'store_credit', 'store credit' => 'store_credit',
            default => 'e_wallet',
        };
    }

    /** @param  array<string,float|int>  $summary @return list<array{type:string,amount:float}> */
    private function discountBreakdown(array $summary): array
    {
        $rows = [
            ['type' => 'promotion', 'amount' => (float) ($summary['promotionDiscount'] ?? 0)],
            ['type' => 'voucher', 'amount' => (float) ($summary['voucherDiscount'] ?? 0)],
            ['type' => 'loyalty', 'amount' => (float) ($summary['loyaltyDiscount'] ?? 0)],
            ['type' => 'manual', 'amount' => (float) ($summary['manualDiscount'] ?? 0)],
            ['type' => 'gift_card', 'amount' => (float) ($summary['giftCardRedemption'] ?? 0), 'informational' => true],
        ];

        return array_values(array_filter(array_map(static function (array $row): array {
            $mapped = [
                'type' => (string) $row['type'],
                'amount' => round((float) $row['amount'], 2),
            ];
            if (! empty($row['informational'])) {
                $mapped['informational'] = true;
            }

            return $mapped;
        }, $rows), static fn (array $row): bool => $row['amount'] > 0 || ($row['type'] ?? '') === 'gift_card'));
    }

    /**
     * @param  list<int>  $orderIds
     * @param  array<int,float>  $voucherDiscountsByOrder
     * @param  array<int,float>  $promotionDiscountsByOrder
     * @param  \Illuminate\Support\Collection<int,object>  $orders
     * @return list<array{productId:string,productName:string,quantity:int,grossSales:float,netSales:float}>
     */
    private function topProducts(array $orderIds, array $voucherDiscountsByOrder, array $promotionDiscountsByOrder, $orders): array
    {
        if ($orderIds === []) {
            return [];
        }

        $discountRatioByOrder = [];
        foreach ($orders as $order) {
            $subtotal = (float) $order->subtotal;
            $voucher = (float) ($voucherDiscountsByOrder[(int) $order->id] ?? 0);
            $promotion = (float) ($promotionDiscountsByOrder[(int) $order->id] ?? 0);
            $columnDiscount = (float) ($order->discount_amount ?? 0);
            $manual = max(0, round($columnDiscount - $voucher - $promotion, 2));
            $totalDiscount = $voucher + $promotion + $manual;
            $discountRatioByOrder[(int) $order->id] = $subtotal > 0 ? min(1, $totalDiscount / $subtotal) : 0.0;
        }

        $rows = DB::table('order_items as oi')
            ->whereIn('oi.order_id', $orderIds)
            ->selectRaw('oi.item_id as product_id, oi.name as product_name, SUM(oi.qty) as quantity, SUM(oi.line_total) as gross_sales, oi.order_id')
            ->groupBy('oi.item_id', 'oi.name', 'oi.order_id')
            ->get();

        $aggregated = [];
        foreach ($rows as $row) {
            $key = (string) $row->product_id.'|'.(string) $row->product_name;
            $gross = (float) $row->gross_sales;
            $ratio = (float) ($discountRatioByOrder[(int) $row->order_id] ?? 0);
            $net = round($gross * (1 - $ratio), 2);
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'productId' => (string) $row->product_id,
                    'productName' => (string) $row->product_name,
                    'quantity' => 0,
                    'grossSales' => 0.0,
                    'netSales' => 0.0,
                ];
            }
            $aggregated[$key]['quantity'] += (int) round((float) $row->quantity);
            $aggregated[$key]['grossSales'] += $gross;
            $aggregated[$key]['netSales'] += $net;
        }

        return collect($aggregated)
            ->sortByDesc('quantity')
            ->take(10)
            ->map(static function (array $row): array {
                return [
                    'productId' => $row['productId'],
                    'productName' => $row['productName'],
                    'quantity' => (int) $row['quantity'],
                    'grossSales' => round((float) $row['grossSales'], 2),
                    'netSales' => round((float) $row['netSales'], 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $currentSummary
     * @return array<string,mixed>|null
     */
    private function buildComparison(
        User $user,
        array $filters,
        Carbon $start,
        Carbon $end,
        array $currentSummary,
        bool $includeAccountingReconciliation,
    ): ?array {
        if (($filters['comparisonPeriod'] ?? null) !== 'previous_period') {
            return null;
        }

        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $prevEnd = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();

        $previousFilters = [
            'startDate' => $prevStart->toDateString(),
            'endDate' => $prevEnd->toDateString(),
        ];
        if (isset($filters['outletId'])) {
            $previousFilters['outletId'] = $filters['outletId'];
        }

        $previous = $this->report($user, $previousFilters, $includeAccountingReconciliation);

        return [
            'period' => [
                'startDate' => $prevStart->toDateString(),
                'endDate' => $prevEnd->toDateString(),
            ],
            'previous' => [
                'finalRevenue' => (float) ($previous['summary']['finalRevenue'] ?? 0),
                'orderCount' => (int) ($previous['summary']['orderCount'] ?? 0),
                'averageOrderValue' => (float) ($previous['summary']['averageOrderValue'] ?? 0),
            ],
            'growth' => [
                'revenueGrowthPercent' => $this->growthPercent(
                    (float) ($previous['summary']['finalRevenue'] ?? 0),
                    (float) ($currentSummary['finalRevenue'] ?? 0),
                ),
                'orderGrowthPercent' => $this->growthPercent(
                    (int) ($previous['summary']['orderCount'] ?? 0),
                    (int) ($currentSummary['orderCount'] ?? 0),
                ),
                'averageOrderValueGrowthPercent' => $this->growthPercent(
                    (float) ($previous['summary']['averageOrderValue'] ?? 0),
                    (float) ($currentSummary['averageOrderValue'] ?? 0),
                ),
            ],
        ];
    }

    private function growthPercent(float|int $previous, float|int $current): float
    {
        $prev = (float) $previous;
        $curr = (float) $current;
        if (abs($prev) < 0.00001) {
            return $curr > 0 ? 100.0 : 0.0;
        }

        return round((($curr - $prev) / $prev) * 100, 2);
    }
}
