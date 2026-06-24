<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\Modules\Accounting\Domain\JournalPostingKey;
use App\Models\Modules\Accounting\Domain\AccountingPeriod;
use App\Models\User;
use App\Modules\Accounting\Support\JournalOutletNameResolver;
use App\Modules\GiftCards\Support\GiftCardRedemptionComposition;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class JournalPostingService
{
    public function __construct(
        private readonly AccountingPeriodService $periodService,
        private readonly PosAuditLogService $auditLogService,
        private readonly AccountingPostingIntegrityService $integrityService,
        private readonly AccountingPostingMappingService $postingMappingService,
        private readonly AccountingPostingFailureService $failureService,
        private readonly AccountingSettingsService $accountingSettingsService,
        private readonly RevenuePostingGuardService $revenuePostingGuard,
    ) {}

    /** @param array<string,mixed> $payload */
    public function post(array $payload): Journal
    {
        $lines = $payload['lines'] ?? [];
        $this->integrityService->validateBeforePost($payload);

        return DB::transaction(function () use ($payload, $lines): Journal {
            $scope = (string) ($payload['scope'] ?? (($payload['source_type'] ?? 'manual').'.'.($payload['source_id'] ?? 'na')));
            $key = isset($payload['posting_key']) ? trim((string) $payload['posting_key']) : null;
            if ($key !== null && $key !== '') {
                $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
                $existingKey = JournalPostingKey::query()
                    ->where('scope', $scope)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();
                if ($existingKey !== null) {
                    if ((string) $existingKey->request_hash !== $requestHash) {
                        throw ValidationException::withMessages(['postingKey' => ['postingKey already used with different payload.']]);
                    }
                    $journal = Journal::query()->with('entries')->findOrFail($existingKey->journal_id);

                    return $journal;
                }
            }

            $journal = Journal::query()->create([
                'tenant_id' => $payload['tenant_id'] ?? null,
                'outlet_id' => $payload['outlet_id'] ?? null,
                'journal_no' => $payload['journal_no'] ?? $this->generateJournalNo(),
                'source_type' => $payload['source_type'] ?? 'manual',
                'source_id' => $payload['source_id'] ?? null,
                'journal_date' => $payload['journal_date'],
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $payload['posted_by'] ?? null,
                'immutable' => true,
                'description' => $payload['description'] ?? null,
                'outlet' => JournalOutletNameResolver::resolve(
                    isset($payload['outlet_id']) ? (int) $payload['outlet_id'] : null,
                    isset($payload['outlet']) ? (string) $payload['outlet'] : null,
                ),
            ]);

            foreach (array_values($lines) as $idx => $line) {
                JournalEntry::query()->create([
                    'journal_id' => $journal->id,
                    'account_id' => (int) $line['account_id'],
                    'debit' => (float) $line['debit'],
                    'credit' => (float) $line['credit'],
                    'memo' => $line['memo'] ?? null,
                    'meta' => $line['meta'] ?? null,
                    'line_no' => $idx + 1,
                ]);
            }

            if (isset($key) && $key !== '') {
                JournalPostingKey::query()->create([
                    'scope' => $scope,
                    'idempotency_key' => $key,
                    'request_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
                    'journal_id' => $journal->id,
                    'processed_at' => now(),
                ]);
            }

            $this->auditLogService->log(
                'accounting.journal.posted',
                'journal',
                (int) $journal->id,
                isset($payload['outlet_id']) ? (int) $payload['outlet_id'] : null,
                null,
                ['source_type' => $payload['source_type'] ?? 'manual', 'source_id' => $payload['source_id'] ?? null]
            );

            return $journal->load(['entries' => fn ($q) => $q->orderBy('line_no')]);
        });
    }

    public function reverse(Journal $journal, ?User $actor = null, ?string $postingKey = null, ?string $reason = null): Journal
    {
        return DB::transaction(function () use ($journal, $actor, $postingKey, $reason): Journal {
            $locked = Journal::query()->with('entries')->lockForUpdate()->findOrFail($journal->id);
            try {
                $this->periodService->assertDateOpen(
                    $locked->journal_date->format('Y-m-d'),
                    $locked->tenant_id !== null ? (int) $locked->tenant_id : null,
                    $locked->outlet_id !== null ? (int) $locked->outlet_id : null
                );
                if ((string) $locked->status !== 'posted') {
                    $this->auditLogService->log('reversal_rejected', 'journal', (int) $locked->id, $locked->outlet_id, $actor, ['reason' => 'draft_not_allowed']);
                    $this->auditLogService->log('period_locked_reversal_rejection', 'journal', (int) $locked->id, $locked->outlet_id, $actor);
                    throw ValidationException::withMessages(['journal' => ['Only posted journals can be reversed.']]);
                }
                if ($locked->reversal_of_journal_id !== null) {
                    $this->auditLogService->log('reversal_rejected', 'journal', (int) $locked->id, $locked->outlet_id, $actor, ['reason' => 'reversal_journal_cannot_be_reversed']);
                    $this->auditLogService->log('period_locked_reversal_rejection', 'journal', (int) $locked->id, $locked->outlet_id, $actor);
                    throw ValidationException::withMessages(['journal' => ['Reversal journals cannot be reversed again.']]);
                }
                if ($locked->reversal_journal_id !== null) {
                    if (is_string($postingKey) && trim($postingKey) !== '') {
                        $scope = 'journal_reversal.'.$locked->id;
                        $existingKey = JournalPostingKey::query()->where('scope', $scope)->where('idempotency_key', trim($postingKey))->first();
                        if ($existingKey !== null) {
                            return Journal::query()->with(['entries' => fn ($q) => $q->orderBy('line_no')])->findOrFail((int) $existingKey->journal_id);
                        }
                    }
                    $this->auditLogService->log('reversal_rejected', 'journal', (int) $locked->id, $locked->outlet_id, $actor, ['reason' => 'already_reversed']);
                    $this->auditLogService->log('period_locked_reversal_rejection', 'journal', (int) $locked->id, $locked->outlet_id, $actor);
                    throw ValidationException::withMessages(['journal' => ['Journal has already been reversed.']]);
                }

                $payload = [
                    'tenant_id' => $locked->tenant_id,
                    'outlet_id' => $locked->outlet_id,
                    'journal_date' => $locked->journal_date->format('Y-m-d'),
                    'description' => 'Reversal of '.$locked->journal_no.($reason ? ' - '.$reason : ''),
                    'outlet' => $locked->outlet,
                    'source_type' => 'journal_reversal',
                    'source_id' => (string) $locked->id,
                    'scope' => 'journal_reversal.'.$locked->id,
                    'posting_key' => $postingKey,
                    'posted_by' => $actor?->id,
                    'lines' => $locked->entries->map(fn (JournalEntry $e): array => [
                        'account_id' => (int) $e->account_id,
                        'debit' => (float) $e->credit,
                        'credit' => (float) $e->debit,
                        'memo' => $e->memo,
                        'meta' => ['reversalOfLine' => (int) $e->id],
                    ])->values()->all(),
                ];
                $reversal = $this->post($payload);
                $reversal->update([
                    'reversal_of_journal_id' => (int) $locked->id,
                    'reversed_journal_id' => (int) $locked->id,
                    'immutable' => true,
                ]);
                $locked->update([
                    'reversal_journal_id' => (int) $reversal->id,
                    'reversed_at' => now(),
                    'reversed_by_user_id' => $actor?->id,
                ]);

                $this->auditLogService->log('reversal_created', 'journal', (int) $locked->id, $locked->outlet_id, $actor, [
                    'reversalJournalId' => (int) $reversal->id,
                    'reason' => $reason,
                ]);

                return $reversal->fresh(['entries' => fn ($q) => $q->orderBy('line_no')]);
            } catch (\Throwable $e) {
                $closedPeriodExists = AccountingPeriod::query()
                    ->where('status', 'closed')
                    ->whereDate('start_date', '<=', $locked->journal_date->format('Y-m-d'))
                    ->whereDate('end_date', '>=', $locked->journal_date->format('Y-m-d'))
                    ->when($locked->tenant_id !== null, fn ($q) => $q->where('tenant_id', (int) $locked->tenant_id))
                    ->when($locked->outlet_id !== null, fn ($q) => $q->where(function ($x) use ($locked) {
                        $x->whereNull('outlet_id')->orWhere('outlet_id', (int) $locked->outlet_id);
                    }))
                    ->exists();
                if ($closedPeriodExists || str_contains(strtolower($e->getMessage()), 'closed accounting period')) {
                    $this->auditLogService->log('period_locked_reversal_rejection', 'journal', (int) $locked->id, $locked->outlet_id, $actor);
                }
                throw $e;
            }
        });
    }

    public function postForOrderPayment(int $orderId, int $tenantId, ?int $outletId, float $sales, float $cogs): ?Journal
    {
        $giftCardComposition = app(\App\Modules\GiftCards\Services\GiftCardAccountingService::class)
            ->compositionFromOrderId($orderId, $outletId);
        $totalRevenue = round($sales + $giftCardComposition->total(), 2);
        if ($totalRevenue <= 0) {
            return null;
        }

        if ($this->accountingSettingsService->isShiftCloseMode($tenantId, $outletId)) {
            return null;
        }

        $duplicate = $this->revenuePostingGuard->shouldSkipDuplicate($orderId, 'order_payment', (string) $orderId, $outletId);
        if ($duplicate !== null) {
            return $duplicate;
        }

        try {
            return app(\App\Modules\GiftCards\Services\GiftCardAccountingService::class)->postOrderPaymentJournal(
                $orderId,
                $tenantId,
                $outletId,
                $sales,
                $giftCardComposition,
                $cogs,
            );
        } catch (\Throwable $e) {
            $this->recordAutoPostFailure('order_payment', $orderId, $outletId, $e, [
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'source_type' => 'order_payment',
                'source_id' => $orderId,
                'journal_date' => now()->toDateString(),
                'description' => 'Auto posting from order payment completion',
                'posting_key' => 'order-payment-'.$orderId,
                'scope' => 'order_payment.'.$orderId,
                'sales' => $sales,
                'cogs' => $cogs,
                'giftCardTotal' => $giftCardComposition->total(),
            ]);

            return null;
        }
    }

    public function postForInventoryMovement(string $type, int $movementId, int $tenantId, ?int $outletId, float $amount): ?Journal
    {
        if ($amount <= 0) {
            return null;
        }

        try {
            $resolvedOutletId = (int) ($outletId ?? 0);
            $inventoryId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_INVENTORY,
                'inventory.asset',
            );
            $counterRuleKey = $type === 'waste' ? 'inventory.waste' : 'inventory.adjustment';
            $counterId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_INVENTORY,
                $counterRuleKey,
            );

            $isCreditInventory = in_array($type, ['waste', 'sale'], true);
            $lines = [
                ['account_id' => $counterId, 'debit' => $isCreditInventory ? $amount : 0, 'credit' => $isCreditInventory ? 0 : $amount, 'memo' => 'Inventory '.$type],
                ['account_id' => $inventoryId, 'debit' => $isCreditInventory ? 0 : $amount, 'credit' => $isCreditInventory ? $amount : 0, 'memo' => 'Inventory '.$type],
            ];

            return $this->post([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'source_type' => 'inventory_'.$type,
                'source_id' => $movementId,
                'journal_date' => now()->toDateString(),
                'description' => 'Auto posting from inventory '.$type,
                'posting_key' => 'inventory-'.$type.'-'.$movementId,
                'scope' => 'inventory_'.$type.'.'.$movementId,
                'lines' => $lines,
            ]);
        } catch (\Throwable $e) {
            $this->recordAutoPostFailure('inventory_'.$type, $movementId, $outletId, $e, [
                'type' => $type,
                'amount' => $amount,
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
            ]);

            return null;
        }
    }

    public function postForCashVariance(int $sessionId, int $tenantId, ?int $outletId, float $variance): ?Journal
    {
        if (abs($variance) < 0.00001) {
            return null;
        }

        try {
            $resolvedOutletId = (int) ($outletId ?? 0);
            $cashId = $this->postingMappingService->resolvePosPaymentAccountId($tenantId, $resolvedOutletId, 'cash');
            $overShortId = $this->postingMappingService->resolveAccountIdOrFail(
                $tenantId,
                $resolvedOutletId,
                AccountingPostingMappingService::MODULE_POS,
                'pos.cash.variance',
            );
            $abs = abs($variance);
            $isShort = $variance < 0;
            $lines = [
                ['account_id' => $overShortId, 'debit' => $isShort ? $abs : 0, 'credit' => $isShort ? 0 : $abs, 'memo' => 'POS cash variance'],
                ['account_id' => $cashId, 'debit' => $isShort ? 0 : $abs, 'credit' => $isShort ? $abs : 0, 'memo' => 'POS cash variance'],
            ];

            return $this->post([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'source_type' => 'pos_cash_variance',
                'source_id' => $sessionId,
                'journal_date' => now()->toDateString(),
                'description' => 'Auto posting from POS cash variance',
                'posting_key' => 'pos-cash-variance-'.$sessionId,
                'scope' => 'pos_cash_variance.'.$sessionId,
                'lines' => $lines,
            ]);
        } catch (\Throwable $e) {
            $this->recordAutoPostFailure('pos_cash_variance', $sessionId, $outletId, $e, [
                'variance' => $variance,
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
            ]);

            return null;
        }
    }

    public function postForShiftClose(
        int $tenantId,
        ?int $outletId,
        float $totalCashSales,
        float $totalCogs,
        string $batchKey,
        ?GiftCardRedemptionComposition $giftCardComposition = null,
        array $paymentAmountsByMethod = [],
    ): ?Journal {
        $giftCardComposition ??= new GiftCardRedemptionComposition;
        $cashFromPayments = round((float) array_sum($paymentAmountsByMethod), 2);
        $effectiveCashSales = $paymentAmountsByMethod !== [] ? $cashFromPayments : $totalCashSales;
        $totalRevenue = round($effectiveCashSales + $giftCardComposition->total(), 2);
        if ($totalRevenue <= 0) {
            return null;
        }

        try {
            $giftCardService = app(\App\Modules\GiftCards\Services\GiftCardAccountingService::class);
            $salesLines = $paymentAmountsByMethod !== []
                ? $giftCardService->buildSalesJournalLinesFromPayments($paymentAmountsByMethod, $giftCardComposition, $outletId, $tenantId)
                : $giftCardService->buildSalesJournalLines($totalCashSales, $giftCardComposition, $outletId, $tenantId);
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

            $lines = [
                ...$salesLines,
                ['account_id' => $cogsId, 'debit' => $totalCogs, 'credit' => 0, 'memo' => 'COGS recognized on shift close'],
                ['account_id' => $inventoryId, 'debit' => 0, 'credit' => $totalCogs, 'memo' => 'Inventory reduction on shift close'],
            ];

            return $this->post([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'source_type' => 'shift_close',
                'source_id' => abs(crc32($batchKey)),
                'journal_date' => now()->toDateString(),
                'description' => 'POS shift close posting',
                'posting_key' => 'shift-close-'.$batchKey,
                'scope' => 'shift_close.'.$outletId,
                'lines' => $lines,
            ]);
        } catch (\Throwable $e) {
            $this->recordAutoPostFailure('shift_close', (int) crc32($batchKey), $outletId, $e, [
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'total_cash_sales' => $totalCashSales,
                'gift_card_total' => $giftCardComposition->total(),
                'total_cogs' => $totalCogs,
                'batch_key' => $batchKey,
            ]);

            return null;
        }
    }

    public function postForDeferredInventoryConsumption(
        int $tenantId,
        ?int $outletId,
        float $totalCogs,
        string $batchKey,
    ): ?Journal {
        if ($totalCogs <= 0) {
            return null;
        }

        try {
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

            return $this->post([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'source_type' => 'inventory_consumption_posting',
                'source_id' => abs(crc32($batchKey)),
                'journal_date' => now()->toDateString(),
                'description' => 'Deferred inventory consumption posting',
                'posting_key' => 'inventory-consumption-'.$batchKey,
                'scope' => 'inventory_consumption.'.$outletId,
                'lines' => [
                    ['account_id' => $cogsId, 'debit' => $totalCogs, 'credit' => 0, 'memo' => 'COGS from deferred consumption'],
                    ['account_id' => $inventoryId, 'debit' => 0, 'credit' => $totalCogs, 'memo' => 'Inventory reduction from deferred consumption'],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->recordAutoPostFailure('inventory_consumption_posting', (int) crc32($batchKey), $outletId, $e, [
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'total_cogs' => $totalCogs,
                'batch_key' => $batchKey,
            ]);

            return null;
        }
    }

    /** @param array<string,mixed>|null $payload */
    private function recordAutoPostFailure(string $sourceType, int $sourceId, ?int $outletId, \Throwable $e, ?array $payload): void
    {
        $this->failureService->record(
            $sourceType,
            $sourceId,
            $outletId,
            $this->integrityService->classifyError($e),
            $e->getMessage(),
            $payload,
        );
    }

    private function generateJournalNo(): string
    {
        return 'JE-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
    }
}
