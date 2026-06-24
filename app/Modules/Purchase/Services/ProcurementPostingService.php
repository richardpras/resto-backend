<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Models\User;
use App\Modules\Accounting\Services\AccountingPostingMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ProcurementPostingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly ThreeWayMatchService $threeWayMatchService,
        private readonly AccountingPostingMappingService $postingMappingService,
    ) {}

    public function postGoodsReceipt(GoodsReceivingNote $grn, ?User $actor = null, bool $throwOnDuplicate = true): ?ProcurementPosting
    {
        abort_if($grn->status !== 'posted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only posted goods receipts can be posted to accounting.');

        $amount = $this->grnAmount($grn);
        abort_if($amount <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Goods receipt value must be greater than zero.');

        return $this->createPosting(
            ProcurementPosting::SOURCE_GRN,
            (int) $grn->id,
            (int) $grn->outlet_id,
            $amount,
            $actor,
            $throwOnDuplicate,
            function () use ($grn, $amount, $actor): array {
                $tenantId = $grn->tenant_id !== null ? (int) $grn->tenant_id : null;
                $outletId = (int) $grn->outlet_id;
                $inventoryId = $this->resolveMappedAccountId($tenantId, $outletId, 'procurement.grn.inventory');
                $grniId = $this->resolveMappedAccountId($tenantId, $outletId, 'procurement.grn.grni');

                return [
                    'journal_date' => optional($grn->posted_at)->format('Y-m-d') ?? now()->toDateString(),
                    'description' => 'GRN posting '.$grn->number,
                    'posting_key' => 'procurement-grn-'.$grn->id,
                    'scope' => 'procurement_grn.'.$grn->id,
                    'source_type' => 'procurement_grn',
                    'source_id' => (string) $grn->id,
                    'outlet_id' => $outletId,
                    'posted_by' => $actor?->id,
                    'lines' => [
                        ['account_id' => $inventoryId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Inventory received'],
                        ['account_id' => $grniId, 'debit' => 0, 'credit' => $amount, 'memo' => 'GRNI accrual'],
                    ],
                ];
            },
            'grn_posted_to_accounting',
        );
    }

    public function postInvoice(PurchaseInvoice $invoice, ?User $actor = null, bool $throwOnDuplicate = true): ?ProcurementPosting
    {
        abort_if(! in_array($invoice->status, ['approved', 'partially_paid', 'paid'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Only approved invoices can be posted to accounting.');
        $this->threeWayMatchService->assertInvoiceApprovable($invoice, $actor);

        $amount = round((float) $invoice->total_amount, 2);
        abort_if($amount <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice total must be greater than zero.');

        return $this->createPosting(
            ProcurementPosting::SOURCE_INVOICE,
            (int) $invoice->id,
            (int) $invoice->outlet_id,
            $amount,
            $actor,
            $throwOnDuplicate,
            function () use ($invoice, $amount, $actor): array {
                $tenantId = $invoice->tenant_id !== null ? (int) $invoice->tenant_id : null;
                $outletId = (int) $invoice->outlet_id;
                $grniId = $this->resolveMappedAccountId($tenantId, $outletId, 'procurement.invoice.grni');
                $apId = $this->resolveMappedAccountId($tenantId, $outletId, 'procurement.invoice.accounts_payable');

                return [
                    'journal_date' => optional($invoice->approved_at)->format('Y-m-d') ?? optional($invoice->invoice_date)->format('Y-m-d') ?? now()->toDateString(),
                    'description' => 'Supplier invoice posting '.$invoice->number,
                    'posting_key' => 'procurement-invoice-'.$invoice->id,
                    'scope' => 'procurement_invoice.'.$invoice->id,
                    'source_type' => 'procurement_invoice',
                    'source_id' => (string) $invoice->id,
                    'outlet_id' => $outletId,
                    'posted_by' => $actor?->id,
                    'lines' => [
                        ['account_id' => $grniId, 'debit' => $amount, 'credit' => 0, 'memo' => 'GRNI clearance'],
                        ['account_id' => $apId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Accounts payable'],
                    ],
                ];
            },
            'invoice_posted_to_accounting',
        );
    }

    public function postPayment(SupplierPayment $payment, ?User $actor = null, bool $throwOnDuplicate = true): ?ProcurementPosting
    {
        abort_if($payment->status !== 'posted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only posted supplier payments can be posted to accounting.');

        $amount = round((float) $payment->allocated_amount, 2);
        abort_if($amount <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment allocated amount must be greater than zero.');

        return $this->createPosting(
            ProcurementPosting::SOURCE_SUPPLIER_PAYMENT,
            (int) $payment->id,
            (int) $payment->outlet_id,
            $amount,
            $actor,
            $throwOnDuplicate,
            function () use ($payment, $amount, $actor): array {
                $tenantId = $payment->tenant_id !== null ? (int) $payment->tenant_id : null;
                $outletId = (int) $payment->outlet_id;
                $apId = $this->resolveMappedAccountId($tenantId, $outletId, 'procurement.payment.accounts_payable');
                $creditAccountId = $this->resolvePaymentCreditAccountId($payment);

                return [
                    'journal_date' => optional($payment->posted_at)->format('Y-m-d') ?? optional($payment->payment_date)->format('Y-m-d') ?? now()->toDateString(),
                    'description' => 'Supplier payment posting '.$payment->payment_no,
                    'posting_key' => 'procurement-payment-'.$payment->id,
                    'scope' => 'procurement_supplier_payment.'.$payment->id,
                    'source_type' => 'procurement_supplier_payment',
                    'source_id' => (string) $payment->id,
                    'outlet_id' => $outletId,
                    'posted_by' => $actor?->id,
                    'lines' => [
                        ['account_id' => $apId, 'debit' => $amount, 'credit' => 0, 'memo' => 'AP settlement'],
                        ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash/Bank payment'],
                    ],
                ];
            },
            'payment_posted_to_accounting',
        );
    }

    public function reversePosting(ProcurementPosting $posting, ?User $actor = null, ?string $notes = null): ProcurementPosting
    {
        abort_if($posting->status !== ProcurementPosting::STATUS_POSTED, Response::HTTP_UNPROCESSABLE_ENTITY, 'Only posted procurement postings can be reversed.');
        abort_if($posting->journal_entry_id === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Journal entry is missing for this posting.');

        return DB::transaction(function () use ($posting, $actor, $notes): ProcurementPosting {
            $journal = Journal::query()->findOrFail((int) $posting->journal_entry_id);
            $this->journalPostingService->reverse($journal, $actor, 'procurement-posting-reverse-'.$posting->id, $notes);

            $posting->update([
                'status' => ProcurementPosting::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actor?->id,
                'notes' => $notes ?? $posting->notes,
            ]);

            $this->purchaseAuditService->logProcurementPosting('reversed', (int) $posting->id, (int) $posting->outlet_id, $actor, [
                'sourceType' => $posting->source_type,
                'sourceId' => (int) $posting->source_id,
            ]);

            return $posting->fresh(['journal']);
        });
    }

    public function getPostingStatus(string $sourceType, int $sourceId): ?ProcurementPosting
    {
        return ProcurementPosting::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderByDesc('id')
            ->first();
    }

    public function attemptAutoPostGoodsReceipt(GoodsReceivingNote $grn, ?User $actor = null): void
    {
        try {
            $this->postGoodsReceipt($grn, $actor, false);
        } catch (\Throwable $e) {
            $this->purchaseAuditService->logProcurementPosting('failed', (int) $grn->id, (int) $grn->outlet_id, $actor, [
                'sourceType' => ProcurementPosting::SOURCE_GRN,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function attemptAutoPostInvoice(PurchaseInvoice $invoice, ?User $actor = null): void
    {
        try {
            $this->postInvoice($invoice, $actor, false);
        } catch (\Throwable $e) {
            $this->purchaseAuditService->logProcurementPosting('failed', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'sourceType' => ProcurementPosting::SOURCE_INVOICE,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function attemptAutoPostPayment(SupplierPayment $payment, ?User $actor = null): void
    {
        try {
            $this->postPayment($payment, $actor, false);
        } catch (\Throwable $e) {
            $this->purchaseAuditService->logProcurementPosting('failed', (int) $payment->id, (int) $payment->outlet_id, $actor, [
                'sourceType' => ProcurementPosting::SOURCE_SUPPLIER_PAYMENT,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return Collection<int, ProcurementPosting> */
    public function list(?User $actor, mixed $requestedOutletId, ?string $sourceType = null, ?string $status = null): Collection
    {
        $query = ProcurementPosting::query()->with(['journal'])->orderByDesc('id');
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        if ($sourceType !== null && $sourceType !== '') {
            $query->where('source_type', $sourceType);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /** @return array<string,float> */
    public function summaryMetrics(?User $actor, mixed $requestedOutletId): array
    {
        $grnQuery = GoodsReceivingNote::query()->where('status', 'posted');
        $invoiceQuery = PurchaseInvoice::query()->whereIn('status', ['approved', 'partially_paid', 'paid']);
        $paymentQuery = SupplierPayment::query()->where('status', 'posted');

        $this->purchaseScopeService->applyOutletScope($grnQuery, $actor, $requestedOutletId);
        $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);
        $this->purchaseScopeService->applyOutletScope($paymentQuery, $actor, $requestedOutletId);

        $postedGrnIds = $this->postedSourceIds(ProcurementPosting::SOURCE_GRN, $actor, $requestedOutletId);
        $postedInvoiceIds = $this->postedSourceIds(ProcurementPosting::SOURCE_INVOICE, $actor, $requestedOutletId);
        $postedPaymentIds = $this->postedSourceIds(ProcurementPosting::SOURCE_SUPPLIER_PAYMENT, $actor, $requestedOutletId);

        $grns = $grnQuery->with('items')->get();
        $invoices = $invoiceQuery->get(['id', 'total_amount']);
        $payments = $paymentQuery->get(['id', 'allocated_amount']);

        $grnPostedValue = 0.0;
        $grnUnpostedValue = 0.0;
        foreach ($grns as $grn) {
            $value = $this->grnAmount($grn);
            if ($postedGrnIds->contains((int) $grn->id)) {
                $grnPostedValue += $value;
            } else {
                $grnUnpostedValue += $value;
            }
        }

        $invoicePostedValue = 0.0;
        $invoiceUnpostedValue = 0.0;
        foreach ($invoices as $invoice) {
            $value = (float) $invoice->total_amount;
            if ($postedInvoiceIds->contains((int) $invoice->id)) {
                $invoicePostedValue += $value;
            } else {
                $invoiceUnpostedValue += $value;
            }
        }

        $paymentPostedValue = 0.0;
        $paymentUnpostedValue = 0.0;
        foreach ($payments as $payment) {
            $value = (float) $payment->allocated_amount;
            if ($postedPaymentIds->contains((int) $payment->id)) {
                $paymentPostedValue += $value;
            } else {
                $paymentUnpostedValue += $value;
            }
        }

        return [
            'postedGrnValue' => round($grnPostedValue, 2),
            'postedInvoiceValue' => round($invoicePostedValue, 2),
            'postedPaymentValue' => round($paymentPostedValue, 2),
            'unpostedGrnValue' => round($grnUnpostedValue, 2),
            'unpostedInvoiceValue' => round($invoiceUnpostedValue, 2),
            'unpostedPaymentValue' => round($paymentUnpostedValue, 2),
        ];
    }

    public function grnAmount(GoodsReceivingNote $grn): float
    {
        $grn->loadMissing('items');

        return round((float) $grn->items->sum(
            static fn ($item): float => (float) $item->received_qty * (float) ($item->actual_received_cost ?? $item->original_po_cost ?? 0)
        ), 2);
    }

    /** @param callable(): array<string,mixed> $journalPayloadBuilder */
    private function createPosting(
        string $sourceType,
        int $sourceId,
        int $outletId,
        float $amount,
        ?User $actor,
        bool $throwOnDuplicate,
        callable $journalPayloadBuilder,
        string $auditAction,
    ): ?ProcurementPosting {
        $existing = ProcurementPosting::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', ProcurementPosting::STATUS_POSTED)
            ->first();

        if ($existing !== null) {
            if ($throwOnDuplicate) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Procurement posting already exists for this document.');
            }

            return $existing;
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $outletId, $amount, $actor, $journalPayloadBuilder, $auditAction): ProcurementPosting {
            $journal = $this->journalPostingService->post($journalPayloadBuilder());

            $posting = ProcurementPosting::query()->create([
                'posting_no' => $this->nextPostingNo($sourceType),
                'outlet_id' => $outletId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'journal_entry_id' => $journal->id,
                'amount' => $amount,
                'status' => ProcurementPosting::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $actor?->id,
            ]);

            $this->purchaseAuditService->logProcurementPosting('created', (int) $posting->id, $outletId, $actor, [
                'sourceType' => $sourceType,
                'sourceId' => $sourceId,
                'journalEntryId' => (int) $journal->id,
            ]);
            $this->purchaseAuditService->logProcurementPosting($auditAction, (int) $posting->id, $outletId, $actor, [
                'sourceType' => $sourceType,
                'sourceId' => $sourceId,
                'amount' => $amount,
            ]);

            return $posting->fresh(['journal']);
        });
    }

    private function resolveMappedAccountId(?int $tenantId, int $outletId, string $ruleKey): int
    {
        return $this->postingMappingService->resolveAccountIdOrFail(
            $tenantId,
            $outletId,
            AccountingPostingMappingService::MODULE_PROCUREMENT,
            $ruleKey,
        );
    }

    private function resolvePaymentCreditAccountId(SupplierPayment $payment): int
    {
        $tenantId = $payment->tenant_id !== null ? (int) $payment->tenant_id : null;
        $outletId = (int) $payment->outlet_id;

        if (in_array($payment->payment_method, ['bank_transfer', 'giro', 'check'], true)) {
            return $this->postingMappingService->resolveBankCreditAccountId(
                $tenantId,
                $outletId,
                $payment->bank_account_id,
            );
        }

        return $this->resolveMappedAccountId($tenantId, $outletId, 'procurement.payment.cash');
    }

    private function nextPostingNo(string $sourceType): string
    {
        $prefix = match ($sourceType) {
            ProcurementPosting::SOURCE_GRN => 'PP-GRN',
            ProcurementPosting::SOURCE_INVOICE => 'PP-INV',
            ProcurementPosting::SOURCE_SUPPLIER_PAYMENT => 'PP-PAY',
            default => 'PP',
        };
        $lastId = (int) (ProcurementPosting::query()->max('id') ?? 0);

        return $prefix.'-'.str_pad((string) ($lastId + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return Collection<int, int> */
    private function postedSourceIds(string $sourceType, ?User $actor, mixed $requestedOutletId): Collection
    {
        $query = ProcurementPosting::query()
            ->where('source_type', $sourceType)
            ->where('status', ProcurementPosting::STATUS_POSTED);
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return $query->pluck('source_id')->map(static fn ($id): int => (int) $id);
    }
}
