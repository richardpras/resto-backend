<?php

namespace App\Modules\GiftCards\Services;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\GiftCards\Domain\GiftCardIssuance;
use App\Models\Modules\GiftCards\Domain\GiftCardLedger;
use App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Modules\Accounting\Services\AccountingAuditService;
use App\Modules\Accounting\Services\AccountingPostingMappingService;
use App\Modules\Accounting\Services\AccountingSettingsService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\GiftCards\Support\GiftCardRedemptionComposition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class GiftCardAccountingService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AccountingPostingMappingService $postingMappingService,
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
        ?int $tenantId = null,
    ): array {
        $cashAmount = round(max(0, $cashAmount), 2);
        $totalRevenue = round($cashAmount + $composition->total(), 2);
        if ($totalRevenue <= 0) {
            return [];
        }

        $resolvedOutletId = (int) ($outletId ?? 0);
        $revenueId = $this->postingMappingService->resolveAccountIdOrFail(
            $tenantId,
            $resolvedOutletId,
            AccountingPostingMappingService::MODULE_POS,
            'pos.sales.revenue',
        );

        $lines = [];
        if ($cashAmount > 0) {
            $cashId = $this->postingMappingService->resolvePosPaymentAccountId(
                $tenantId,
                $resolvedOutletId,
                'cash',
            );
            $lines[] = [
                'account_id' => $cashId,
                'debit' => $cashAmount,
                'credit' => 0,
                'memo' => 'Payment settlement',
            ];
        }

        if ($composition->giftCardAmount > 0) {
            $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.redemption.gift_card',
            );
            $lines[] = [
                'account_id' => $liabilityId,
                'debit' => $composition->giftCardAmount,
                'credit' => 0,
                'memo' => 'Gift card redemption',
            ];
        }

        if ($composition->storeCreditAmount > 0) {
            $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.redemption.store_credit',
            );
            $lines[] = [
                'account_id' => $liabilityId,
                'debit' => $composition->storeCreditAmount,
                'credit' => 0,
                'memo' => 'Store credit redemption',
            ];
        }

        $lines[] = [
            'account_id' => $revenueId,
            'debit' => 0,
            'credit' => $totalRevenue,
            'memo' => 'Revenue recognition',
        ];

        return $lines;
    }

    /**
     * Build sales journal lines with per-payment-method debit accounts.
     *
     * @param  array<string, float>  $paymentAmountsByMethod  settlement method => amount
     * @return list<array{account_id:int,debit:float,credit:float,memo:string}>
     */
    public function buildSalesJournalLinesFromPayments(
        array $paymentAmountsByMethod,
        GiftCardRedemptionComposition $composition,
        ?int $outletId,
        ?int $tenantId = null,
    ): array {
        $filtered = [];
        foreach ($paymentAmountsByMethod as $method => $amount) {
            $rounded = round(max(0, (float) $amount), 2);
            if ($rounded > 0) {
                $filtered[(string) $method] = ($filtered[(string) $method] ?? 0) + $rounded;
            }
        }

        $cashTotal = round((float) array_sum($filtered), 2);
        if ($cashTotal <= 0 && $composition->isEmpty()) {
            return [];
        }

        if ($filtered === [] && $cashTotal <= 0) {
            return $this->buildSalesJournalLines(0, $composition, $outletId, $tenantId);
        }

        $resolvedOutletId = (int) ($outletId ?? 0);
        $revenueId = $this->postingMappingService->resolveAccountIdOrFail(
            $tenantId,
            $resolvedOutletId,
            AccountingPostingMappingService::MODULE_POS,
            'pos.sales.revenue',
        );
        $totalRevenue = round($cashTotal + $composition->total(), 2);
        if ($totalRevenue <= 0) {
            return [];
        }

        $debitByAccount = [];
        foreach ($filtered as $method => $amount) {
            $accountId = $this->postingMappingService->resolvePosPaymentAccountId(
                $tenantId,
                $resolvedOutletId,
                (string) $method,
                (string) $method,
            );
            $debitByAccount[$accountId] = round(($debitByAccount[$accountId] ?? 0) + $amount, 2);
        }

        $lines = [];
        foreach ($debitByAccount as $accountId => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $lines[] = [
                'account_id' => $accountId,
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Payment settlement',
            ];
        }

        if ($composition->giftCardAmount > 0) {
            $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.redemption.gift_card',
            );
            $lines[] = [
                'account_id' => $liabilityId,
                'debit' => $composition->giftCardAmount,
                'credit' => 0,
                'memo' => 'Gift card redemption',
            ];
        }

        if ($composition->storeCreditAmount > 0) {
            $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.redemption.store_credit',
            );
            $lines[] = [
                'account_id' => $liabilityId,
                'debit' => $composition->storeCreditAmount,
                'credit' => 0,
                'memo' => 'Store credit redemption',
            ];
        }

        $lines[] = [
            'account_id' => $revenueId,
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
        $method = strtolower(trim((string) ($transaction->payment_method ?? 'qris')));
        $cashAmount = (float) $transaction->amount;
        $lines = $this->buildSalesJournalLinesFromPayments(
            [$method => $cashAmount],
            $composition,
            $outletId,
            $tenantId,
        );
        if ($lines === []) {
            $lines = $this->buildSalesJournalLines($cashAmount, $composition, $outletId, $tenantId);
        }
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
        $paymentAmounts = $this->paymentAmountsForOrder($orderId);
        $lines = $paymentAmounts !== []
            ? $this->buildSalesJournalLinesFromPayments($paymentAmounts, $composition, $outletId, $tenantId)
            : $this->buildSalesJournalLines($cashPaid, $composition, $outletId, $tenantId);
        if ($lines === []) {
            return null;
        }

        if ($cogs > 0) {
            $resolvedOutletId = (int) ($outletId ?? 0);
            $cogsId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.sales.cogs',
            );
            $inventoryId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.sales.inventory',
            );
            $lines[] = ['account_id' => $cogsId, 'debit' => $cogs, 'credit' => 0, 'memo' => 'COGS recognition'];
            $lines[] = ['account_id' => $inventoryId, 'debit' => 0, 'credit' => $cogs, 'memo' => 'Inventory reduction'];
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
        $liabilityRuleKey = $instrument === 'store_credit'
            ? 'pos.redemption.store_credit'
            : 'pos.redemption.gift_card';

        $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
            null,
            $outletId,
            AccountingPostingMappingService::MODULE_POS,
            $liabilityRuleKey,
        );
        $breakageId = $this->postingMappingService->resolveAccountIdOrFail(
            null,
            $outletId,
            AccountingPostingMappingService::MODULE_POS,
            'pos.gift_card.breakage.revenue',
        );

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
                    'account_id' => $liabilityId,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Expired gift card liability relief',
                ],
                [
                    'account_id' => $breakageId,
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
        $liabilityRuleKey = $instrument === 'store_credit'
            ? 'pos.gift_card.issue.store_credit'
            : 'pos.gift_card.issue.gift_card';

        try {
            $cashId = $this->postingMappingService->resolveAccountIdOrFail(
                null,
                $outletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.gift_card.issue.cash',
            );
            $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
                null,
                $outletId,
                AccountingPostingMappingService::MODULE_POS,
                $liabilityRuleKey,
            );
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
                    'account_id' => $cashId,
                    'debit' => $cashReceivedAmount,
                    'credit' => 0,
                    'memo' => 'Gift card issue proceeds'.($paymentMethod ? ' ('.$paymentMethod.')' : ''),
                ],
                [
                    'account_id' => $liabilityId,
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

        try {
            $resolvedOutletId = (int) $outletId;
            $revenueId = $this->postingMappingService->resolveAccountIdOrFail(
                null,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.sales.revenue',
            );
            $ruleKey = $instrument === 'store_credit' ? 'pos.redemption.store_credit' : 'pos.redemption.gift_card';
            $liabilityId = $this->postingMappingService->resolveAccountIdOrFail(
                null,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                $ruleKey,
            );
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
                    'account_id' => $revenueId,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Revenue reversal — gift card refund',
                ],
                [
                    'account_id' => $liabilityId,
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

    /** @return array<string, float> */
    public function paymentAmountsForOrder(int $orderId): array
    {
        $rows = Payment::query()
            ->where('order_id', $orderId)
            ->where('status', '!=', 'void')
            ->get(['method', 'amount']);

        $amounts = [];
        foreach ($rows as $payment) {
            $method = strtolower(trim((string) $payment->method));
            if ($method === '') {
                $method = 'cash';
            }
            $amounts[$method] = round(($amounts[$method] ?? 0) + (float) $payment->amount, 2);
        }

        return $amounts;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<string, float>
     */
    public function paymentAmountsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = Payment::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', '!=', 'void')
            ->get(['method', 'amount']);

        $amounts = [];
        foreach ($rows as $payment) {
            $method = strtolower(trim((string) $payment->method));
            if ($method === '') {
                $method = 'cash';
            }
            $amounts[$method] = round(($amounts[$method] ?? 0) + (float) $payment->amount, 2);
        }

        return $amounts;
    }
}
