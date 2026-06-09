<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Journal;

final class RevenuePostingGuardService
{
    /** @var list<string> */
    private const REVENUE_SOURCE_TYPES = ['order_payment', 'payment_transaction', 'shift_close'];

    public function __construct(
        private readonly AccountingAuditService $accountingAuditService,
    ) {}

    /**
     * Returns an existing revenue journal for the order when duplicate posting must be prevented.
     */
    public function findExistingRevenueJournal(int $orderId): ?Journal
    {
        $byOrderPayment = Journal::query()
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->first();

        if ($byOrderPayment !== null) {
            return $byOrderPayment;
        }

        return Journal::query()
            ->where('source_type', 'payment_transaction')
            ->whereIn('source_id', function ($query) use ($orderId): void {
                $query->select('id')
                    ->from('payment_transactions')
                    ->where('order_id', $orderId);
            })
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->first();
    }

    public function shouldSkipDuplicate(int $orderId, string $proposedSourceType, int|string $proposedSourceId, ?int $outletId = null): ?Journal
    {
        if (! in_array($proposedSourceType, self::REVENUE_SOURCE_TYPES, true)) {
            return null;
        }

        $existing = $this->findExistingRevenueJournal($orderId);
        if ($existing === null) {
            return null;
        }

        if ((string) $existing->source_type === $proposedSourceType
            && (string) $existing->source_id === (string) $proposedSourceId) {
            return $existing;
        }

        $this->accountingAuditService->log(
            'revenue_duplicate_prevented',
            'order',
            $orderId,
            $outletId,
            null,
            [
                'existingJournalId' => (int) $existing->id,
                'existingSourceType' => (string) $existing->source_type,
                'existingSourceId' => (string) $existing->source_id,
                'proposedSourceType' => $proposedSourceType,
                'proposedSourceId' => (string) $proposedSourceId,
            ],
        );

        return $existing;
    }
}
