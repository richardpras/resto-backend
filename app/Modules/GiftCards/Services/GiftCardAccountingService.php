<?php

namespace App\Modules\GiftCards\Services;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\GiftCards\Domain\GiftCardIssuance;
use App\Models\Modules\GiftCards\Domain\GiftCardLedger;
use App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Modules\Accounting\Services\AccountingAuditService;
use App\Modules\Accounting\Services\AccountingPostingIntegrityService;
use App\Modules\Accounting\Services\AccountingSettingsService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\GiftCards\Support\GiftCardRedemptionComposition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class GiftCardAccountingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AccountingPostingIntegrityService $integrityService,
        private readonly AccountingAuditService $accountingAuditService,
        private readonly AccountingSettingsService $accountingSettingsService,
    ) {}

    /** @param list<int> $settlementIds */
    public function compositionFromSettlementIds(array $settlementIds, ?int $outletId = null): GiftCardRedemptionComposition
    {
        if ($settlementIds === []) {
            return new GiftCardRedemptionComposition;
        }

        $settlements = GiftCardRedemptionSettlement::query()
            ->with(['ledgerEntry.issuance'])
            ->whereIn('id', array_map('intval', $settlementIds))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->get();

        return $this->compositionFromSettlements($settlements);
    }

    /** @param list<int> $orderIds */
    public function compositionFromOrderIds(array $orderIds, ?int $outletId = null, bool $settledOnly = false): GiftCardRedemptionComposition
    {
        if ($orderIds === []) {
            return new GiftCardRedemptionComposition;
        }

        $ledgerIds = GiftCardLedger::query()
            ->where('reference_type', 'order')
            ->whereIn('reference_id', array_map('strval', $orderIds))
            ->where('transaction_type', 'redeem')
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->pluck('id')
            ->all();

        if ($ledgerIds === []) {
            return new GiftCardRedemptionComposition;
        }

        $query = GiftCardRedemptionSettlement::query()
            ->with(['ledgerEntry.issuance'])
            ->whereIn('ledger_entry_id', $ledgerIds);
        if ($settledOnly) {
            $query->where('status', 'settled');
        }

        return $this->compositionFromSettlements($query->get());
    }

    public function compositionFromOrderId(int $orderId, ?int $outletId = null, bool $settledOnly = false): GiftCardRedemptionComposition
    {
        $ledgerIds = GiftCardLedger::query()
            ->where('reference_type', 'order')
            ->where('reference_id', (string) $orderId)
            ->where('transaction_type', 'redeem')
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->pluck('id')
            ->all();

        if ($ledgerIds === []) {
            return new GiftCardRedemptionComposition;
        }

        $query = GiftCardRedemptionSettlement::query()
            ->with(['ledgerEntry.issuance'])
            ->whereIn('ledger_entry_id', $ledgerIds);
        if ($settledOnly) {
            $query->where('status', 'settled');
        }

        return $this->compositionFromSettlements($query->get());
    }

    /** @param Collection<int, GiftCardRedemptionSettlement> $settlements */
    public function compositionFromSettlements(Collection $settlements): GiftCardRedemptionComposition
    {
        $giftCard = 0.0;
        $storeCredit = 0.0;
        $ids = [];

        foreach ($settlements as $settlement) {
            $amount = (float) $settlement->redeemed_amount;
            if ($amount <= 0) {
                continue;
            }
            $ids[] = (int) $settlement->id;
            $instrument = strtolower((string) ($settlement->ledgerEntry?->issuance?->instrument_type ?? 'gift_card'));
            if ($instrument === 'store_credit') {
                $storeCredit += $amount;
            } else {
                $giftCard += $amount;
            }
        }

        return new GiftCardRedemptionComposition(
            giftCardAmount: round($giftCard, 2),
            storeCreditAmount: round($storeCredit, 2),
            settlementIds: $ids,
        );
    }

    /**
     * @return list<array{account_id:int,debit:float,credit:float,memo:string}>
     */
    public function buildSalesJournalLines(
        float $cashAmount,
        GiftCardRedemptionComposition $composition,
        ?int $outletId,
    ): array {
        $cashAmount = round(max(0, $cashAmount), 2);
        $totalRevenue = round($cashAmount + $composition->total(), 2);
        if ($totalRevenue <= 0) {
            return [];
        }

        $cash = $this->integrityService->resolveAccountOrFail('cash_bank', ['1100'], ['asset'], $outletId);
        $revenue = $this->integrityService->resolveAccountOrFail('sales_revenue', ['4100'], ['revenue'], $outletId);

        $lines = [];
        if ($cashAmount > 0) {
            $lines[] = [
                'account_id' => (int) $cash->id,
                'debit' => $cashAmount,
                'credit' => 0,
                'memo' => 'Payment settlement',
            ];
        }

        if ($composition->giftCardAmount > 0) {
            $liability = $this->integrityService->resolveAccountOrFail(
                'gift_card_liability',
                ['2130'],
                ['liability'],
                $outletId,
            );
            $lines[] = [
                'account_id' => (int) $liability->id,
                'debit' => $composition->giftCardAmount,
                'credit' => 0,
                'memo' => 'Gift card redemption',
            ];
        }

        if ($composition->storeCreditAmount > 0) {
            $liability = $this->integrityService->resolveAccountOrFail(
                'store_credit_liability',
                ['2135'],
                ['liability'],
                $outletId,
            );
            $lines[] = [
                'account_id' => (int) $liability->id,
                'debit' => $composition->storeCreditAmount,
                'credit' => 0,
                'memo' => 'Store credit redemption',
            ];
        }

        $lines[] = [
            'account_id' => (int) $revenue->id,
            'debit' => 0,
            'credit' => $totalRevenue,
            'memo' => 'Revenue recognition',
        ];

        return $lines;
    }

    public function postPaymentTransactionJournal(
        PaymentTransaction $transaction,
        int $tenantId,
        int $outletId,
        GiftCardRedemptionComposition $composition,
    ): ?Journal {
        $cashAmount = (float) $transaction->amount;
        $lines = $this->buildSalesJournalLines($cashAmount, $composition, $outletId);
        if ($lines === []) {
            return null;
        }

        return $this->journalPostingService->post([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'source_type' => 'payment_transaction',
            'source_id' => (string) $transaction->id,
            'journal_date' => now()->toDateString(),
            'description' => 'Auto posting from payment transaction paid transition',
            'posting_key' => 'payment-transaction-'.$transaction->id,
            'scope' => 'payment_transaction.'.$transaction->id,
            'lines' => $lines,
        ]);
    }

    public function postOrderPaymentJournal(
        int $orderId,
        int $tenantId,
        ?int $outletId,
        float $cashPaid,
        GiftCardRedemptionComposition $composition,
        float $cogs = 0.0,
    ): ?Journal {
        $lines = $this->buildSalesJournalLines($cashPaid, $composition, $outletId);
        if ($lines === []) {
            return null;
        }

        if ($cogs > 0) {
            $cogsAcc = $this->integrityService->resolveAccount('cogs', ['5100'], ['expense'], $outletId);
            $inventory = $this->integrityService->resolveAccount('inventory', ['1300'], ['asset'], $outletId);
            if ($cogsAcc !== null && $inventory !== null) {
                $lines[] = ['account_id' => (int) $cogsAcc->id, 'debit' => $cogs, 'credit' => 0, 'memo' => 'COGS recognition'];
                $lines[] = ['account_id' => (int) $inventory->id, 'debit' => 0, 'credit' => $cogs, 'memo' => 'Inventory reduction'];
            }
        }

        return $this->journalPostingService->post([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'source_type' => 'order_payment',
            'source_id' => (string) $orderId,
            'journal_date' => now()->toDateString(),
            'description' => 'Auto posting from order payment completion',
            'posting_key' => 'order-payment-'.$orderId,
            'scope' => 'order_payment.'.$orderId,
            'lines' => $lines,
        ]);
    }

    /**
     * Supplemental gift card revenue for cash/direct settlements (no payment_transaction_id).
     */
    public function postSettledRedemptionRevenue(GiftCardRedemptionSettlement $settlement): ?Journal
    {
        if ((string) $settlement->status !== 'settled') {
            return null;
        }
        if ($settlement->payment_transaction_id !== null && (int) $settlement->payment_transaction_id > 0) {
            return null;
        }

        $outletId = (int) $settlement->outlet_id;
        if ($this->accountingSettingsService->isShiftCloseMode(null, $outletId)) {
            return null;
        }

        $existingKey = 'gift-card-revenue-settlement-'.$settlement->id;
        $existing = Journal::query()
            ->where('source_type', 'gift_card_settlement')
            ->where('source_id', (string) $settlement->id)
            ->where('status', 'posted')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $composition = $this->compositionFromSettlements(collect([$settlement->fresh(['ledgerEntry.issuance'])]));
        if ($composition->isEmpty()) {
            return null;
        }

        $lines = $this->buildSalesJournalLines(0, $composition, $outletId);
        if ($lines === []) {
            return null;
        }

        $journal = $this->journalPostingService->post([
            'tenant_id' => 0,
            'outlet_id' => $outletId,
            'source_type' => 'gift_card_settlement',
            'source_id' => (string) $settlement->id,
            'journal_date' => now()->toDateString(),
            'description' => 'Gift card redemption revenue recognition',
            'posting_key' => $existingKey,
            'scope' => 'gift_card_settlement.'.$settlement->id,
            'lines' => $lines,
        ]);

        $meta = is_array($settlement->meta) ? $settlement->meta : [];
        $meta['journalId'] = (int) $journal->id;
        $settlement->update(['meta' => $meta]);

        return $journal;
    }

    public function postExpiryBreakage(GiftCardIssuance $issuance, float $expiredAmount): ?Journal
    {
        if ($expiredAmount <= 0) {
            return null;
        }

        $outletId = (int) $issuance->outlet_id;
        $instrument = strtolower((string) $issuance->instrument_type);
        $liabilityCategory = $instrument === 'store_credit' ? 'store_credit_liability' : 'gift_card_liability';
        $liabilityCodes = $instrument === 'store_credit' ? ['2135'] : ['2130'];

        $liability = $this->integrityService->resolveAccount($liabilityCategory, $liabilityCodes, ['liability'], $outletId);
        $breakage = $this->integrityService->resolveAccount('gift_card_breakage', ['4190'], ['revenue'], $outletId);
        if ($liability === null || $breakage === null) {
            Log::info('Gift card expiry breakage skipped: missing account mapping.', [
                'issuance_id' => (int) $issuance->id,
                'outlet_id' => $outletId,
            ]);

            return null;
        }

        $amount = round($expiredAmount, 2);
        $postingKey = 'gift-card-expiry-'.$issuance->id;

        return $this->journalPostingService->post([
            'tenant_id' => 0,
            'outlet_id' => $outletId,
            'source_type' => 'gift_card_expiry',
            'source_id' => (string) $issuance->id,
            'journal_date' => now()->toDateString(),
            'description' => 'Gift card/store credit expiry breakage',
            'posting_key' => $postingKey,
            'scope' => 'gift_card_expiry.'.$issuance->id,
            'lines' => [
                [
                    'account_id' => (int) $liability->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Expired gift card liability relief',
                ],
                [
                    'account_id' => (int) $breakage->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Gift card breakage revenue',
                ],
            ],
        ]);
    }

    public function postIssueLiability(
        GiftCardIssuance $issuance,
        float $cashReceivedAmount,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
    ): ?Journal {
        $cashReceivedAmount = round($cashReceivedAmount, 2);
        if ($cashReceivedAmount <= 0) {
            return null;
        }

        $outletId = (int) $issuance->outlet_id;
        $postingKey = 'gift-card-issue-'.$issuance->id;

        $existing = Journal::query()
            ->where('source_type', 'gift_card_issue')
            ->where('source_id', (string) $issuance->id)
            ->where('status', 'posted')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $instrument = strtolower((string) $issuance->instrument_type);
        $liabilityCategory = $instrument === 'store_credit' ? 'store_credit_liability' : 'gift_card_liability';
        $liabilityCodes = $instrument === 'store_credit' ? ['2135'] : ['2130'];

        try {
            $cash = $this->integrityService->resolveAccountOrFail('cash_bank', ['1100'], ['asset'], $outletId);
            $liability = $this->integrityService->resolveAccountOrFail($liabilityCategory, $liabilityCodes, ['liability'], $outletId);
        } catch (\Throwable $e) {
            Log::info('Gift card issue liability skipped: missing account mapping.', [
                'issuance_id' => (int) $issuance->id,
                'outlet_id' => $outletId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->journalPostingService->post([
            'tenant_id' => 0,
            'outlet_id' => $outletId,
            'source_type' => 'gift_card_issue',
            'source_id' => (string) $issuance->id,
            'journal_date' => now()->toDateString(),
            'description' => 'Gift card/store credit issuance liability',
            'posting_key' => $postingKey,
            'scope' => 'gift_card_issue.'.$issuance->id,
            'lines' => [
                [
                    'account_id' => (int) $cash->id,
                    'debit' => $cashReceivedAmount,
                    'credit' => 0,
                    'memo' => 'Gift card issue proceeds'.($paymentMethod ? ' ('.$paymentMethod.')' : ''),
                ],
                [
                    'account_id' => (int) $liability->id,
                    'debit' => 0,
                    'credit' => $cashReceivedAmount,
                    'memo' => 'Gift card/store credit liability recognized — '.(string) $issuance->code,
                ],
            ],
        ]);
    }

    /** @return list<array{settlementId:int,journalId:int|null,balanceRestored:float}> */
    public function reverseRedemptionForOrder(int $orderId, ?int $outletId, ?User $actor = null, ?string $refundKey = null): array
    {
        $settlements = GiftCardRedemptionSettlement::query()
            ->with(['ledgerEntry.issuance'])
            ->where('status', 'settled')
            ->whereHas('ledgerEntry', function ($query) use ($orderId, $outletId): void {
                $query->where('reference_type', 'order')
                    ->where('reference_id', (string) $orderId)
                    ->where('transaction_type', 'redeem');
                if ($outletId !== null && $outletId > 0) {
                    $query->where('outlet_id', $outletId);
                }
            })
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->get();

        if ($settlements->isEmpty()) {
            return [];
        }

        $results = [];
        foreach ($settlements as $settlement) {
            $results[] = DB::transaction(function () use ($settlement, $orderId, $outletId, $actor, $refundKey): array {
                return $this->reverseSingleSettlement($settlement, $orderId, $outletId, $actor, $refundKey);
            });
        }

        return $results;
    }

    /** @return array{settlementId:int,journalId:int|null,balanceRestored:float} */
    private function reverseSingleSettlement(
        GiftCardRedemptionSettlement $settlement,
        int $orderId,
        ?int $outletId,
        ?User $actor,
        ?string $refundKey,
    ): array {
        $locked = GiftCardRedemptionSettlement::query()
            ->with(['ledgerEntry.issuance'])
            ->lockForUpdate()
            ->findOrFail((int) $settlement->id);

        if ((string) $locked->status === 'reversed') {
            $meta = is_array($locked->meta) ? $locked->meta : [];

            return [
                'settlementId' => (int) $locked->id,
                'journalId' => isset($meta['refundJournalId']) ? (int) $meta['refundJournalId'] : null,
                'balanceRestored' => 0.0,
            ];
        }

        $refundLedgerKey = 'refund-reversal#'.$locked->id.($refundKey ? '#'.$refundKey : '');
        $existingRefundLedger = GiftCardLedger::query()
            ->where('outlet_id', (int) $locked->outlet_id)
            ->where('idempotency_key', $refundLedgerKey)
            ->first();
        if ($existingRefundLedger !== null) {
            $meta = is_array($locked->meta) ? $locked->meta : [];

            return [
                'settlementId' => (int) $locked->id,
                'journalId' => isset($meta['refundJournalId']) ? (int) $meta['refundJournalId'] : null,
                'balanceRestored' => 0.0,
            ];
        }

        $issuance = GiftCardIssuance::query()->lockForUpdate()->findOrFail((int) $locked->issuance_id);
        $amount = round((float) $locked->redeemed_amount, 2);
        $balanceBefore = round((float) $issuance->balance_amount, 2);
        $balanceAfter = round($balanceBefore + $amount, 2);

        GiftCardLedger::query()->create([
            'issuance_id' => (int) $issuance->id,
            'outlet_id' => (int) $locked->outlet_id,
            'created_by_user_id' => $actor?->id,
            'transaction_type' => 'refund',
            'idempotency_key' => $refundLedgerKey,
            'reference_type' => 'order',
            'reference_id' => (string) $orderId,
            'amount_delta' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'meta' => ['settlementId' => (int) $locked->id, 'refundKey' => $refundKey],
            'occurred_at' => now(),
        ]);

        $issuance->update([
            'balance_amount' => $balanceAfter,
            'status' => 'active',
        ]);

        $refundJournal = $this->postRedemptionReversalJournal($locked, $orderId);

        $meta = is_array($locked->meta) ? $locked->meta : [];
        if ($refundJournal !== null) {
            $meta['refundJournalId'] = (int) $refundJournal->id;
        }
        $locked->update([
            'status' => 'reversed',
            'meta' => $meta,
        ]);

        $this->accountingAuditService->log(
            'gift_card_redemption_reversed',
            'order',
            $orderId,
            $outletId,
            $actor,
            [
                'settlementId' => (int) $locked->id,
                'amount' => $amount,
                'balanceAfter' => $balanceAfter,
                'refundJournalId' => $refundJournal?->id,
            ],
        );

        return [
            'settlementId' => (int) $locked->id,
            'journalId' => $refundJournal?->id,
            'balanceRestored' => $amount,
        ];
    }

    private function postRedemptionReversalJournal(
        GiftCardRedemptionSettlement $settlement,
        int $orderId,
    ): ?Journal {
        $amount = round((float) $settlement->redeemed_amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $outletId = (int) $settlement->outlet_id;
        $postingKey = 'gift-card-refund-settlement-'.$settlement->id;

        $existing = Journal::query()
            ->where('source_type', 'gift_card_refund')
            ->where('source_id', (string) $settlement->id)
            ->where('status', 'posted')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($this->isGiftCardPortionCoveredByReversedPaymentJournal($settlement, $orderId)) {
            return null;
        }

        $settlementJournal = Journal::query()
            ->where('source_type', 'gift_card_settlement')
            ->where('source_id', (string) $settlement->id)
            ->where('status', 'posted')
            ->whereNull('reversal_journal_id')
            ->first();
        if ($settlementJournal !== null) {
            return $this->journalPostingService->reverse(
                $settlementJournal,
                null,
                $postingKey.'-reverse-settlement',
                'Gift card settlement refund reversal',
            );
        }

        $instrument = strtolower((string) ($settlement->ledgerEntry?->issuance?->instrument_type ?? 'gift_card'));
        $liabilityCategory = $instrument === 'store_credit' ? 'store_credit_liability' : 'gift_card_liability';
        $liabilityCodes = $instrument === 'store_credit' ? ['2135'] : ['2130'];

        try {
            $revenue = $this->integrityService->resolveAccountOrFail('sales_revenue', ['4100'], ['revenue'], $outletId);
            $liability = $this->integrityService->resolveAccountOrFail($liabilityCategory, $liabilityCodes, ['liability'], $outletId);
        } catch (\Throwable $e) {
            Log::info('Gift card refund reversal skipped: missing account mapping.', [
                'settlement_id' => (int) $settlement->id,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->journalPostingService->post([
            'tenant_id' => 0,
            'outlet_id' => $outletId,
            'source_type' => 'gift_card_refund',
            'source_id' => (string) $settlement->id,
            'journal_date' => now()->toDateString(),
            'description' => 'Gift card redemption refund reversal',
            'posting_key' => $postingKey,
            'scope' => 'gift_card_refund.'.$settlement->id,
            'lines' => [
                [
                    'account_id' => (int) $revenue->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Revenue reversal — gift card refund',
                ],
                [
                    'account_id' => (int) $liability->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Gift card liability restored',
                ],
            ],
        ]);
    }

    private function isGiftCardPortionCoveredByReversedPaymentJournal(
        GiftCardRedemptionSettlement $settlement,
        int $orderId,
    ): bool {
        $paymentTransactionId = $settlement->payment_transaction_id;
        if ($paymentTransactionId === null || (int) $paymentTransactionId <= 0) {
            return false;
        }

        $paymentJournal = Journal::query()
            ->where('source_type', 'payment_transaction')
            ->where('source_id', (string) $paymentTransactionId)
            ->where('status', 'posted')
            ->first();

        return $paymentJournal !== null && $paymentJournal->reversal_journal_id !== null;
    }

    public function auditRefundWithGiftCardExposure(int $orderId, ?int $outletId): void
    {
        $composition = $this->compositionFromOrderId($orderId, $outletId, settledOnly: true);
        if ($composition->isEmpty()) {
            return;
        }

        $this->accountingAuditService->log(
            'gift_card_refund_exposure',
            'order',
            $orderId,
            $outletId,
            null,
            [
                'giftCardAmount' => $composition->giftCardAmount,
                'storeCreditAmount' => $composition->storeCreditAmount,
                'message' => 'Legacy audit — use reverseRedemptionForOrder instead.',
            ],
        );
    }
}
