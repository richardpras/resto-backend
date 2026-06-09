<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\HR\Domain\PayrollPosting;
use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Models\User;
use App\Modules\HR\Services\PayrollPostingService;
use App\Modules\Purchase\Services\ProcurementPostingService;

final class AccountingVoidPostingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly ProcurementPostingService $procurementPostingService,
        private readonly PayrollPostingService $payrollPostingService,
        private readonly AccountingAuditService $accountingAuditService,
    ) {}

    public function voidSupplierPayment(SupplierPayment $payment, ?User $actor = null): ?Journal
    {
        $posting = ProcurementPosting::query()
            ->where('source_type', ProcurementPosting::SOURCE_SUPPLIER_PAYMENT)
            ->where('source_id', (int) $payment->id)
            ->where('status', ProcurementPosting::STATUS_POSTED)
            ->orderByDesc('id')
            ->first();

        if ($posting === null) {
            return null;
        }

        $reversed = $this->procurementPostingService->reversePosting($posting, $actor, 'Supplier payment voided');

        $this->accountingAuditService->log(
            'void_posted',
            'supplier_payment',
            (int) $payment->id,
            $payment->outlet_id !== null ? (int) $payment->outlet_id : null,
            $actor,
            ['procurementPostingId' => (int) $posting->id],
        );

        return $reversed->journal;
    }

    public function reverseJournalBySource(string $sourceType, int $sourceId, ?User $actor = null, ?string $reason = null): ?Journal
    {
        $journal = Journal::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->first();

        if ($journal === null || $journal->reversal_journal_id !== null) {
            return $journal !== null && $journal->reversal_journal_id !== null
                ? Journal::query()->find($journal->reversal_journal_id)
                : null;
        }

        $reversal = $this->journalPostingService->reverse(
            $journal,
            $actor,
            'void-'.$sourceType.'-'.$sourceId,
            $reason ?? 'Document voided',
        );

        $this->accountingAuditService->log(
            'void_posted',
            $sourceType,
            $sourceId,
            $journal->outlet_id !== null ? (int) $journal->outlet_id : null,
            $actor,
            ['reversalJournalId' => (int) $reversal->id],
        );

        return $reversal;
    }

    public function voidPosOrderPayment(int $orderId, ?int $outletId, ?User $actor = null): ?Journal
    {
        $reversal = $this->reverseJournalBySource('order_payment', $orderId, $actor, 'POS payment void');
        if ($reversal !== null) {
            $this->accountingAuditService->log(
                'pos_payment_voided',
                'order_payment',
                $orderId,
                $outletId,
                $actor,
                ['reversalJournalId' => (int) $reversal->id],
            );
        }

        return $reversal;
    }

    public function voidPostedOrderCancellation(int $orderId, ?int $outletId, ?User $actor = null): ?Journal
    {
        $journal = Journal::query()
            ->where('status', 'posted')
            ->where(function ($q) use ($orderId): void {
                $q->where(function ($x) use ($orderId): void {
                    $x->where('source_type', 'order_payment')->where('source_id', (string) $orderId);
                })->orWhere(function ($x) use ($orderId): void {
                    $x->where('source_type', 'payment_transaction')
                        ->whereIn('source_id', function ($sub) use ($orderId): void {
                            $sub->select('id')->from('payment_transactions')->where('order_id', $orderId);
                        });
                });
            })
            ->orderByDesc('id')
            ->first();

        if ($journal === null || $journal->reversal_journal_id !== null) {
            return $journal !== null && $journal->reversal_journal_id !== null
                ? Journal::query()->find($journal->reversal_journal_id)
                : null;
        }

        $reversal = $this->journalPostingService->reverse(
            $journal,
            $actor,
            'void-order-cancel-'.$orderId,
            'Order cancelled after posting',
        );

        $this->accountingAuditService->log(
            'pos_refund_posted',
            'order',
            $orderId,
            $outletId,
            $actor,
            ['reversalJournalId' => (int) $reversal->id, 'originalJournalId' => (int) $journal->id],
        );

        return $reversal;
    }

    public function voidPayrollRun(int $runId, ?User $actor = null): ?PayrollPosting
    {
        try {
            return $this->payrollPostingService->reverse($actor, $runId);
        } catch (\Throwable) {
            return null;
        }
    }
}
