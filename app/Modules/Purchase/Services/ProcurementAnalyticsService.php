<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\ProcurementMatchResult;
use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProcurementAnalyticsService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly AccountsPayableSummaryService $accountsPayableSummaryService,
        private readonly AccountsPayableAgingService $accountsPayableAgingService,
        private readonly ProcurementPostingService $procurementPostingService,
    ) {}

    /** @return array<string,mixed> */
    public function summary(?User $actor, mixed $requestedOutletId): array
    {
        $cycles = $this->cycleMetrics($actor, $requestedOutletId);
        $posting = $this->posting($actor, $requestedOutletId);
        $topSupplier = $this->topSupplier($actor, $requestedOutletId);

        return [
            'totalSpend' => round($this->totalSpend($actor, $requestedOutletId), 2),
            'totalPurchaseOrders' => $this->scopedCount(PurchaseOrder::query(), $actor, $requestedOutletId),
            'totalReceipts' => $this->scopedCount(GoodsReceivingNote::query()->where('status', 'posted'), $actor, $requestedOutletId),
            'totalInvoices' => $this->scopedCount(
                PurchaseInvoice::query()->whereIn('status', ['approved', 'partially_paid', 'paid']),
                $actor,
                $requestedOutletId
            ),
            'totalPayments' => $this->scopedCount(SupplierPayment::query()->where('status', 'posted'), $actor, $requestedOutletId),
            'outstandingPayables' => round($this->accountsPayableSummaryService->outstandingPayablesTotal($actor, $requestedOutletId), 2),
            'overduePayables' => round($this->overduePayablesAmount($actor, $requestedOutletId), 2),
            'averagePoCycleDays' => $cycles['averagePoCycleDays'],
            'averageInvoiceCycleDays' => $cycles['averageInvoiceCycleDays'],
            'averageSupplierLeadTime' => $cycles['averageReceivingCycleDays'],
            'matchRate' => $this->matchRate($actor, $requestedOutletId),
            'postingRate' => $posting['postingRate'],
            'topSupplier' => $topSupplier,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function suppliers(?User $actor, mixed $requestedOutletId): array
    {
        $poQuery = PurchaseOrder::query()->with(['supplier', 'items']);
        $this->purchaseScopeService->applyOutletScope($poQuery, $actor, $requestedOutletId);
        $orders = $poQuery->whereNotNull('supplier_id')->get();

        $invoiceQuery = PurchaseInvoice::query()->with(['latestMatchResult', 'purchaseOrder']);
        $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);
        $invoices = $invoiceQuery->whereIn('status', ['approved', 'partially_paid', 'paid', 'submitted'])->get();

        $grnQuery = GoodsReceivingNote::query()->with('purchaseOrder');
        $this->purchaseScopeService->applyOutletScope($grnQuery, $actor, $requestedOutletId);
        $grns = $grnQuery->where('status', 'posted')->get();

        $supplierIds = $orders->pluck('supplier_id')
            ->merge($invoices->map(static fn (PurchaseInvoice $inv): int => (int) ($inv->supplier_id ?? $inv->purchaseOrder?->supplier_id ?? 0)))
            ->filter(static fn ($id): bool => (int) $id > 0)
            ->unique()
            ->map(static fn ($id): int => (int) $id);

        $rows = [];
        foreach ($supplierIds as $supplierId) {
            $supplierOrders = $orders->where('supplier_id', $supplierId);
            $supplierInvoices = $invoices->filter(
                static fn (PurchaseInvoice $inv): bool => (int) ($inv->supplier_id ?? $inv->purchaseOrder?->supplier_id ?? 0) === $supplierId
            );
            $supplierGrns = $grns->filter(static fn (GoodsReceivingNote $grn): bool => (int) ($grn->purchaseOrder?->supplier_id ?? 0) === $supplierId);

            $purchaseAmount = (float) $supplierInvoices
                ->whereIn('status', ['approved', 'partially_paid', 'paid'])
                ->sum(static fn (PurchaseInvoice $inv): float => (float) $inv->total_amount);

            if ($purchaseAmount <= 0) {
                $purchaseAmount = (float) $supplierOrders->sum(static function (PurchaseOrder $po): float {
                    return (float) $po->items->sum(static fn ($item): float => (float) $item->ordered_qty * (float) $item->unit_price);
                });
            }

            $leadTimes = [];
            $onTime = 0;
            $onTimeTotal = 0;
            foreach ($supplierOrders as $po) {
                if ($po->approved_at === null) {
                    continue;
                }
                $firstGrn = $supplierGrns
                    ->where('purchase_order_id', $po->id)
                    ->sortBy('posted_at')
                    ->first();
                if ($firstGrn?->posted_at === null) {
                    continue;
                }
                $days = $po->approved_at->diffInDays($firstGrn->posted_at);
                $leadTimes[] = $days;
                $onTimeTotal++;
                if ($days <= 30) {
                    $onTime++;
                }
            }

            $matchResults = $supplierInvoices->map(static fn (PurchaseInvoice $inv) => $inv->latestMatchResult)->filter();
            $matched = $matchResults->whereIn('match_status', ['matched', 'matched_with_tolerance'])->count();
            $matchTotal = $matchResults->count();

            $supplier = Supplier::query()->find($supplierId);

            $rows[] = [
                'supplierId' => (string) $supplierId,
                'supplierName' => $supplier?->name ?? 'Unknown Supplier',
                'purchaseAmount' => round($purchaseAmount, 2),
                'purchaseCount' => $supplierOrders->count(),
                'averageLeadTime' => $leadTimes !== [] ? round(array_sum($leadTimes) / count($leadTimes), 2) : 0.0,
                'onTimeDeliveryRate' => $onTimeTotal > 0 ? round(($onTime / $onTimeTotal) * 100, 2) : 0.0,
                'invoiceAccuracyRate' => $matchTotal > 0 ? round(($matched / $matchTotal) * 100, 2) : 100.0,
                'matchRate' => $matchTotal > 0 ? round(($matched / $matchTotal) * 100, 2) : 100.0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['purchaseAmount'] <=> $a['purchaseAmount']);

        return $rows;
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function spend(?User $actor, mixed $requestedOutletId, array $filters = []): array
    {
        $from = isset($filters['fromDate']) ? Carbon::parse((string) $filters['fromDate'])->startOfDay() : now()->subMonths(11)->startOfMonth();
        $to = isset($filters['toDate']) ? Carbon::parse((string) $filters['toDate'])->endOfDay() : now()->endOfDay();

        $invoiceQuery = PurchaseInvoice::query()
            ->with(['items.ingredient', 'supplier'])
            ->whereIn('status', ['approved', 'partially_paid', 'paid'])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);

        $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);
        if (! empty($filters['supplierId'])) {
            $invoiceQuery->where('supplier_id', (int) $filters['supplierId']);
        }

        $invoices = $invoiceQuery->get();

        $monthly = [];
        $supplierSpend = [];
        $categorySpend = [];
        $warehouseSpend = [];

        foreach ($invoices as $invoice) {
            $monthKey = optional($invoice->invoice_date)->format('Y-m') ?? now()->format('Y-m');
            $monthly[$monthKey] = ($monthly[$monthKey] ?? 0) + (float) $invoice->total_amount;

            $supplierKey = (string) ($invoice->supplier_id ?? '0');
            $supplierSpend[$supplierKey] = ($supplierSpend[$supplierKey] ?? 0) + (float) $invoice->total_amount;

            foreach ($invoice->items as $item) {
                $category = (string) ($item->ingredient?->type ?? 'uncategorized');
                $lineAmount = (float) ($item->line_subtotal > 0
                    ? $item->line_subtotal
                    : (float) ($item->invoiced_qty ?? $item->qty) * (float) ($item->unit_cost ?? 0));
                $categorySpend[$category] = ($categorySpend[$category] ?? 0) + $lineAmount;
            }
        }

        $grnQuery = GoodsReceivingNote::query()
            ->with(['items', 'warehouse'])
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$from, $to]);
        $this->purchaseScopeService->applyOutletScope($grnQuery, $actor, $requestedOutletId);
        if (! empty($filters['warehouseId'])) {
            $grnQuery->where(function (Builder $q) use ($filters): void {
                $wid = (int) $filters['warehouseId'];
                $q->where('warehouse_id', $wid)->orWhere('destination_warehouse_id', $wid);
            });
        }

        foreach ($grnQuery->get() as $grn) {
            $warehouseKey = (string) ($grn->warehouse_id ?? $grn->destination_warehouse_id ?? '0');
            $warehouseSpend[$warehouseKey] = ($warehouseSpend[$warehouseKey] ?? 0) + $this->procurementPostingService->grnAmount($grn);
        }

        if (! empty($filters['categoryId'])) {
            $categorySpend = array_filter(
                $categorySpend,
                static fn ($_, string $key): bool => $key === (string) $filters['categoryId'],
                ARRAY_FILTER_USE_BOTH
            );
        }

        return [
            'monthlySpend' => $this->formatMonthlySeries($monthly, $from, $to),
            'supplierSpend' => $this->formatNamedAmounts($supplierSpend, Supplier::class, 'name'),
            'categorySpend' => $this->formatCategorySpend($categorySpend),
            'warehouseSpend' => $this->formatWarehouseSpend($warehouseSpend),
        ];
    }

    /** @return array<string,float> */
    public function payables(?User $actor, mixed $requestedOutletId): array
    {
        $aging = $this->accountsPayableAgingService->report($actor, $requestedOutletId);
        $totals = $aging['totals'];

        return [
            'current' => (float) ($totals['current'] ?? 0),
            'days1to30' => (float) ($totals['days1to30'] ?? 0),
            'days31to60' => (float) ($totals['days31to60'] ?? 0),
            'days61to90' => (float) ($totals['days61to90'] ?? 0),
            'days90plus' => (float) ($totals['days90plus'] ?? 0),
            'totalOutstanding' => (float) ($totals['total'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function trends(?User $actor, mixed $requestedOutletId): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }

        $poCounts = $this->monthlyCounts(PurchaseOrder::query(), 'created_at', $actor, $requestedOutletId, $months);
        $grnCounts = $this->monthlyCounts(GoodsReceivingNote::query()->where('status', 'posted'), 'posted_at', $actor, $requestedOutletId, $months);
        $invoiceCounts = $this->monthlyCounts(
            PurchaseInvoice::query()->whereIn('status', ['approved', 'partially_paid', 'paid']),
            'approved_at',
            $actor,
            $requestedOutletId,
            $months
        );
        $paymentCounts = $this->monthlyCounts(SupplierPayment::query()->where('status', 'posted'), 'posted_at', $actor, $requestedOutletId, $months);
        $spend = $this->monthlySpend($actor, $requestedOutletId, $months);

        return [
            'months' => $months,
            'purchaseOrders' => array_values($poCounts),
            'receipts' => array_values($grnCounts),
            'invoices' => array_values($invoiceCounts),
            'payments' => array_values($paymentCounts),
            'spend' => array_values($spend),
        ];
    }

    /** @return array<string,mixed> */
    public function posting(?User $actor, mixed $requestedOutletId): array
    {
        $postedGrnIds = $this->postedSourceIds(ProcurementPosting::SOURCE_GRN, $actor, $requestedOutletId);
        $postedInvoiceIds = $this->postedSourceIds(ProcurementPosting::SOURCE_INVOICE, $actor, $requestedOutletId);
        $postedPaymentIds = $this->postedSourceIds(ProcurementPosting::SOURCE_SUPPLIER_PAYMENT, $actor, $requestedOutletId);

        $grnEligible = $this->scopedCount(GoodsReceivingNote::query()->where('status', 'posted'), $actor, $requestedOutletId);
        $invoiceEligible = $this->scopedCount(
            PurchaseInvoice::query()->whereIn('status', ['approved', 'partially_paid', 'paid']),
            $actor,
            $requestedOutletId
        );
        $paymentEligible = $this->scopedCount(SupplierPayment::query()->where('status', 'posted'), $actor, $requestedOutletId);

        $postedGrnCount = $postedGrnIds->count();
        $postedInvoiceCount = $postedInvoiceIds->count();
        $postedPaymentCount = $postedPaymentIds->count();

        $totalEligible = $grnEligible + $invoiceEligible + $paymentEligible;
        $totalPosted = $postedGrnCount + $postedInvoiceCount + $postedPaymentCount;

        return [
            'postedGrnCount' => $postedGrnCount,
            'postedInvoiceCount' => $postedInvoiceCount,
            'postedPaymentCount' => $postedPaymentCount,
            'unpostedGrnCount' => max(0, $grnEligible - $postedGrnCount),
            'unpostedInvoiceCount' => max(0, $invoiceEligible - $postedInvoiceCount),
            'unpostedPaymentCount' => max(0, $paymentEligible - $postedPaymentCount),
            'postingRate' => $totalEligible > 0 ? round(($totalPosted / $totalEligible) * 100, 2) : 0.0,
        ];
    }

    /** @return array<string,float> */
    private function cycleMetrics(?User $actor, mixed $requestedOutletId): array
    {
        $poCycleDays = [];
        $poQuery = PurchaseOrder::query()->with('purchaseRequest')->whereNotNull('approved_at');
        $this->purchaseScopeService->applyOutletScope($poQuery, $actor, $requestedOutletId);
        foreach ($poQuery->get() as $po) {
            $pr = $po->purchaseRequest;
            if ($pr?->submitted_at !== null && $po->approved_at !== null) {
                $poCycleDays[] = $pr->submitted_at->diffInDays($po->approved_at);
            }
        }

        $receivingCycleDays = [];
        $grnQuery = GoodsReceivingNote::query()->with('purchaseOrder')->where('status', 'posted')->whereNotNull('posted_at');
        $this->purchaseScopeService->applyOutletScope($grnQuery, $actor, $requestedOutletId);
        foreach ($grnQuery->get() as $grn) {
            if ($grn->purchaseOrder?->approved_at !== null) {
                $receivingCycleDays[] = $grn->purchaseOrder->approved_at->diffInDays($grn->posted_at);
            }
        }

        $invoiceCycleDays = [];
        $paymentCycleDays = [];
        $invoiceQuery = PurchaseInvoice::query()
            ->with(['goodsReceivingNote', 'supplierPaymentAllocations.supplierPayment'])
            ->whereIn('status', ['approved', 'partially_paid', 'paid'])
            ->whereNotNull('approved_at');
        $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);
        foreach ($invoiceQuery->get() as $invoice) {
            if ($invoice->goodsReceivingNote?->posted_at !== null) {
                $invoiceCycleDays[] = $invoice->goodsReceivingNote->posted_at->diffInDays($invoice->approved_at);
            }

            $payment = $invoice->supplierPaymentAllocations
                ->map(static fn ($a) => $a->supplierPayment)
                ->filter(static fn ($p) => $p !== null && $p->status === 'posted' && $p->posted_at !== null)
                ->sortBy('posted_at')
                ->first();
            if ($payment !== null) {
                $paymentCycleDays[] = $invoice->approved_at->diffInDays($payment->posted_at);
            }
        }

        return [
            'averagePoCycleDays' => $this->average($poCycleDays),
            'averageReceivingCycleDays' => $this->average($receivingCycleDays),
            'averageInvoiceCycleDays' => $this->average($invoiceCycleDays),
            'averagePaymentCycleDays' => $this->average($paymentCycleDays),
        ];
    }

    private function totalSpend(?User $actor, mixed $requestedOutletId): float
    {
        $query = PurchaseInvoice::query()->whereIn('status', ['approved', 'partially_paid', 'paid']);
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return (float) $query->sum('total_amount');
    }

    private function overduePayablesAmount(?User $actor, mixed $requestedOutletId): float
    {
        $query = PurchaseInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->whereDate('due_date', '<', now()->toDateString());
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return (float) $query->get()->sum(static fn (PurchaseInvoice $inv): float => max(
            0,
            (float) $inv->total_amount - (float) $inv->paid_amount
        ));
    }

    private function matchRate(?User $actor, mixed $requestedOutletId): float
    {
        $matchQuery = ProcurementMatchResult::query()
            ->whereIn('id', function ($sub): void {
                $sub->selectRaw('MAX(id)')->from('procurement_match_results')->groupBy('invoice_id');
            })
            ->whereHas('purchaseInvoice', function ($query) use ($actor, $requestedOutletId): void {
                $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);
            });

        $total = (int) (clone $matchQuery)->count();
        if ($total === 0) {
            return 0.0;
        }

        $matched = (int) (clone $matchQuery)->whereIn('match_status', ['matched', 'matched_with_tolerance'])->count();

        return round(($matched / $total) * 100, 2);
    }

    /** @return array<string,string|float>|null */
    private function topSupplier(?User $actor, mixed $requestedOutletId): ?array
    {
        $suppliers = $this->suppliers($actor, $requestedOutletId);
        if ($suppliers === []) {
            return null;
        }

        $top = $suppliers[0];

        return [
            'supplierId' => $top['supplierId'],
            'supplierName' => $top['supplierName'],
            'purchaseAmount' => $top['purchaseAmount'],
        ];
    }

    /** @param list<string> $months
     * @return array<string,int>
     */
    private function monthlyCounts(Builder $query, string $dateColumn, ?User $actor, mixed $requestedOutletId, array $months): array
    {
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);
        $counts = array_fill_keys($months, 0);

        foreach ($query->get() as $row) {
            $date = $row->{$dateColumn} ?? null;
            if ($date === null) {
                continue;
            }
            $key = Carbon::parse($date)->format('Y-m');
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /** @param list<string> $months
     * @return array<string,float>
     */
    private function monthlySpend(?User $actor, mixed $requestedOutletId, array $months): array
    {
        $query = PurchaseInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid', 'paid'])
            ->whereNotNull('invoice_date');
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        $spend = array_fill_keys($months, 0.0);
        foreach ($query->get() as $invoice) {
            $key = optional($invoice->invoice_date)->format('Y-m');
            if ($key !== null && isset($spend[$key])) {
                $spend[$key] += (float) $invoice->total_amount;
            }
        }

        return $spend;
    }

    /** @param array<string,float> $monthly */
    private function formatMonthlySeries(array $monthly, Carbon $from, Carbon $to): array
    {
        $series = [];
        $cursor = $from->copy()->startOfMonth();
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'month' => $key,
                'amount' => round((float) ($monthly[$key] ?? 0), 2),
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    /** @param array<string,float> $amounts */
    private function formatNamedAmounts(array $amounts, string $modelClass, string $nameColumn): array
    {
        $rows = [];
        foreach ($amounts as $id => $amount) {
            $model = $modelClass::query()->find((int) $id);
            $rows[] = [
                'id' => (string) $id,
                'name' => $model?->{$nameColumn} ?? 'Unknown',
                'amount' => round($amount, 2),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    /** @param array<string,float> $categorySpend */
    private function formatCategorySpend(array $categorySpend): array
    {
        $rows = [];
        foreach ($categorySpend as $category => $amount) {
            $rows[] = [
                'categoryId' => (string) $category,
                'categoryName' => ucfirst(str_replace('_', ' ', $category)),
                'amount' => round($amount, 2),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    /** @param array<string,float> $warehouseSpend */
    private function formatWarehouseSpend(array $warehouseSpend): array
    {
        $rows = [];
        foreach ($warehouseSpend as $warehouseId => $amount) {
            $name = DB::table('warehouses')->where('id', (int) $warehouseId)->value('name');

            $rows[] = [
                'warehouseId' => (string) $warehouseId,
                'warehouseName' => $name ?? 'Unknown Warehouse',
                'amount' => round($amount, 2),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    /** @param list<float|int> $values */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }

    private function scopedCount(Builder $query, ?User $actor, mixed $requestedOutletId): int
    {
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return (int) $query->count();
    }

    /** @return \Illuminate\Support\Collection<int,int> */
    private function postedSourceIds(string $sourceType, ?User $actor, mixed $requestedOutletId): \Illuminate\Support\Collection
    {
        $query = ProcurementPosting::query()
            ->where('source_type', $sourceType)
            ->where('status', ProcurementPosting::STATUS_POSTED);
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return $query->pluck('source_id')->map(static fn ($id): int => (int) $id);
    }
}
