<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\User;

final class AccountingRefundPostingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AccountingAuditService $accountingAuditService,
    ) {}

    public function postRefundForPaymentTransaction(PaymentTransaction $transaction, ?User $actor = null): ?Journal
    {
        $journal = $this->findOriginalJournal($transaction);
        if ($journal === null) {
            return null;
        }

        if ($journal->reversal_journal_id !== null) {
            return Journal::query()->find($journal->reversal_journal_id);
        }

        $reversal = $this->journalPostingService->reverse(
            $journal,
            $actor,
            'refund-payment-transaction-'.$transaction->id,
            'POS payment refund',
        );

        $this->accountingAuditService->log(
            'refund_posted',
            'payment_transaction',
            (int) $transaction->id,
            $transaction->outlet_id !== null ? (int) $transaction->outlet_id : null,
            $actor,
            ['originalJournalId' => (int) $journal->id, 'reversalJournalId' => (int) $reversal->id],
        );

        return $reversal;
    }

    public function postRefundForOrder(int $orderId, float $amount, ?int $outletId, ?User $actor = null): ?Journal
    {
        $journal = Journal::query()
            ->where('source_type', 'order_payment')
            ->where('source_id', $orderId)
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->first();

        if ($journal === null) {
            return null;
        }

        if ($journal->reversal_journal_id !== null) {
            return Journal::query()->find($journal->reversal_journal_id);
        }

        $reversal = $this->journalPostingService->reverse(
            $journal,
            $actor,
            'refund-order-'.$orderId,
            'POS order refund',
        );

        $this->accountingAuditService->log(
            'refund_posted',
            'order_payment',
            $orderId,
            $outletId,
            $actor,
            ['originalJournalId' => (int) $journal->id, 'reversalJournalId' => (int) $reversal->id, 'amount' => $amount],
        );

        return $reversal;
    }

    private function findOriginalJournal(PaymentTransaction $transaction): ?Journal
    {
        $byTx = Journal::query()
            ->where('source_type', 'payment_transaction')
            ->where('source_id', (string) $transaction->id)
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->first();

        if ($byTx !== null) {
            return $byTx;
        }

        return Journal::query()
            ->where('source_type', 'order_payment')
            ->where('source_id', (int) $transaction->order_id)
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->first();
    }
}
