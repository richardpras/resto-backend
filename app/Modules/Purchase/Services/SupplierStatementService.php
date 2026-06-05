<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Models\Supplier;
use App\Models\User;

final class SupplierStatementService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @return array<string, mixed> */
    public function statement(?User $actor, mixed $requestedOutletId, int $supplierId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $supplier = Supplier::query()->findOrFail($supplierId);

        $invoiceQuery = PurchaseInvoice::query()
            ->where('supplier_id', $supplierId)
            ->whereNotIn('status', ['draft', 'submitted', 'void']);

        $paymentQuery = SupplierPayment::query()
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted');

        $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);
        $this->purchaseScopeService->applyOutletScope($paymentQuery, $actor, $requestedOutletId);

        if ($fromDate !== null) {
            $invoiceQuery->whereDate('invoice_date', '>=', $fromDate);
            $paymentQuery->whereDate('payment_date', '>=', $fromDate);
        }
        if ($toDate !== null) {
            $invoiceQuery->whereDate('invoice_date', '<=', $toDate);
            $paymentQuery->whereDate('payment_date', '<=', $toDate);
        }

        $invoices = $invoiceQuery->orderBy('invoice_date')->get();
        $payments = $paymentQuery->with('allocations')->orderBy('payment_date')->get();

        $totalInvoiced = (float) $invoices->sum(static fn (PurchaseInvoice $inv): float => (float) $inv->total_amount);
        $totalPaid = (float) $payments->sum(static fn (SupplierPayment $pay): float => (float) $pay->allocated_amount);
        $outstanding = max(0, (float) PurchaseInvoice::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['approved', 'partially_paid'])
            ->when($requestedOutletId !== null, function ($q) use ($actor, $requestedOutletId): void {
                $this->purchaseScopeService->applyOutletScope($q, $actor, $requestedOutletId);
            })
            ->get()
            ->sum(static fn (PurchaseInvoice $inv): float => max(0, (float) $inv->total_amount - (float) $inv->paid_amount)));

        return [
            'supplierId' => (string) $supplier->id,
            'supplierName' => $supplier->name,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'totalInvoiced' => round($totalInvoiced, 2),
            'totalPaid' => round($totalPaid, 2),
            'outstanding' => round($outstanding, 2),
            'invoices' => $invoices->map(static fn (PurchaseInvoice $inv): array => [
                'id' => (string) $inv->id,
                'invoiceNumber' => $inv->number,
                'date' => optional($inv->invoice_date)->format('Y-m-d'),
                'dueDate' => optional($inv->due_date)->format('Y-m-d'),
                'total' => (float) $inv->total_amount,
                'paidAmount' => (float) $inv->paid_amount,
                'outstandingAmount' => max(0, (float) $inv->total_amount - (float) $inv->paid_amount),
                'status' => $inv->status,
            ])->values()->all(),
            'payments' => $payments->map(static fn (SupplierPayment $pay): array => [
                'id' => (string) $pay->id,
                'paymentNo' => $pay->payment_no,
                'date' => optional($pay->payment_date)->format('Y-m-d'),
                'amount' => (float) $pay->amount,
                'allocatedAmount' => (float) $pay->allocated_amount,
                'paymentMethod' => $pay->payment_method,
                'referenceNo' => $pay->reference_no,
            ])->values()->all(),
        ];
    }
}
