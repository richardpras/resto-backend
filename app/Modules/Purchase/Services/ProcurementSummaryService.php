<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\ProcurementMatchResult;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseInvoicePayment;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Procurement\Models\PurchaseRequest;

final class ProcurementSummaryService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly AccountsPayableSummaryService $accountsPayableSummaryService,
    ) {}

    /** @return array<string,int> */
    public function summary(?User $actor, mixed $requestedOutletId): array
    {
        $poQuery = PurchaseOrder::query();
        $grnQuery = GoodsReceivingNote::query();
        $invoiceQuery = PurchaseInvoice::query();

        $this->purchaseScopeService->applyOutletScope($poQuery, $actor, $requestedOutletId);
        $this->purchaseScopeService->applyOutletScope($grnQuery, $actor, $requestedOutletId);
        $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);

        $paymentQuery = PurchaseInvoicePayment::query()
            ->whereHas('purchaseInvoice', function ($query) use ($actor, $requestedOutletId): void {
                $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);
            });

        $supplierPaymentQuery = SupplierPayment::query();
        $this->purchaseScopeService->applyOutletScope($supplierPaymentQuery, $actor, $requestedOutletId);

        $prQuery = PurchaseRequest::query();
        $this->purchaseScopeService->applyOutletScope($prQuery, $actor, $requestedOutletId);

        $matchQuery = ProcurementMatchResult::query()
            ->whereIn('id', function ($sub): void {
                $sub->selectRaw('MAX(id)')
                    ->from('procurement_match_results')
                    ->groupBy('invoice_id');
            })
            ->whereHas('purchaseInvoice', function ($query) use ($actor, $requestedOutletId): void {
                $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);
            });

        $matchedCount = (int) (clone $matchQuery)->where('match_status', 'matched')->count();
        $matchedWithToleranceCount = (int) (clone $matchQuery)->where('match_status', 'matched_with_tolerance')->count();
        $mismatchCount = (int) (clone $matchQuery)->where('match_status', 'mismatch')->count();
        $blockedCount = (int) (clone $matchQuery)->where('match_status', 'blocked')->count();
        $totalMatchedInvoices = $matchedCount + $matchedWithToleranceCount;
        $totalMatchResults = (int) (clone $matchQuery)->count();
        $matchRate = $totalMatchResults > 0
            ? round(($totalMatchedInvoices / $totalMatchResults) * 100, 2)
            : 0.0;

        return [
            'totalSuppliers' => Supplier::query()->count(),
            'totalPurchaseOrders' => (int) $poQuery->count(),
            'totalGoodsReceipts' => (int) $grnQuery->count(),
            'totalPurchaseInvoices' => (int) $invoiceQuery->count(),
            'totalPurchasePayments' => (int) $paymentQuery->count(),
            'purchaseRequests' => (int) $prQuery->count(),
            'submittedRequests' => (int) (clone $prQuery)->where('status', 'submitted')->count(),
            'approvedRequests' => (int) (clone $prQuery)->where('status', 'approved')->count(),
            'convertedRequests' => (int) (clone $prQuery)->where('status', 'converted')->count(),
            'draftPOs' => (int) (clone $poQuery)->where('status', 'draft')->count(),
            'submittedPOs' => (int) (clone $poQuery)->where('status', 'submitted')->count(),
            'approvedPOs' => (int) (clone $poQuery)->where('status', 'approved')->count(),
            'partiallyReceivedPOs' => (int) (clone $poQuery)->where('status', 'partially_received')->count(),
            'receivedPOs' => (int) (clone $poQuery)->where('status', 'received')->count(),
            'cancelledPOs' => (int) (clone $poQuery)->where('status', 'cancelled')->count(),
            'draftReceivings' => (int) (clone $grnQuery)->where('status', 'draft')->count(),
            'receivedReceivings' => (int) (clone $grnQuery)->where('status', 'received')->count(),
            'postedReceivings' => (int) (clone $grnQuery)->where('status', 'posted')->count(),
            'cancelledReceivings' => (int) (clone $grnQuery)->where('status', 'cancelled')->count(),
            'todayReceivings' => (int) (clone $grnQuery)->whereDate('posted_at', now()->toDateString())->count(),
            'todayReceivedValue' => (float) GoodsReceivingNote::query()
                ->when(true, function ($query) use ($actor, $requestedOutletId): void {
                    $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);
                })
                ->where('status', 'posted')
                ->whereDate('posted_at', now()->toDateString())
                ->with('items')
                ->get()
                ->sum(static fn (GoodsReceivingNote $grn): float => $grn->items->sum(
                    static fn ($item): float => (float) $item->received_qty * (float) ($item->actual_received_cost ?? $item->original_po_cost ?? 0)
                )),
            'draftInvoices' => (int) (clone $invoiceQuery)->where('status', 'draft')->count(),
            'submittedInvoices' => (int) (clone $invoiceQuery)->where('status', 'submitted')->count(),
            'approvedInvoices' => (int) (clone $invoiceQuery)->where('status', 'approved')->count(),
            'paidInvoices' => (int) (clone $invoiceQuery)->where('status', 'paid')->count(),
            'overdueInvoices' => $this->accountsPayableSummaryService->overdueInvoicesCount($actor, $requestedOutletId),
            'outstandingPayables' => round($this->accountsPayableSummaryService->outstandingPayablesTotal($actor, $requestedOutletId), 2),
            'totalPayments' => (int) $supplierPaymentQuery->count(),
            'postedPayments' => (int) (clone $supplierPaymentQuery)->where('status', 'posted')->count(),
            'voidedPayments' => (int) (clone $supplierPaymentQuery)->where('status', 'void')->count(),
            'apPaidAmount' => round((float) (clone $supplierPaymentQuery)->where('status', 'posted')->sum('allocated_amount'), 2),
            'apOutstandingAmount' => round($this->accountsPayableSummaryService->outstandingPayablesTotal($actor, $requestedOutletId), 2),
            'matchedInvoices' => $totalMatchedInvoices,
            'mismatchInvoices' => $mismatchCount,
            'blockedInvoices' => $blockedCount,
            'matchRate' => $matchRate,
        ];
    }
}
