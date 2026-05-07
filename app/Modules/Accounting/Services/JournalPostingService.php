<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\Modules\Accounting\Domain\JournalPostingKey;
use App\Models\Modules\Accounting\Domain\AccountingPeriod;
use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class JournalPostingService
{
    public function __construct(
        private readonly AccountingPeriodService $periodService,
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string,mixed> $payload */
    public function post(array $payload): Journal
    {
        $lines = $payload['lines'] ?? [];
        $this->assertBalancedLines($lines);
        $this->periodService->assertDateOpen(
            (string) $payload['journal_date'],
            isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null,
            isset($payload['outlet_id']) ? (int) $payload['outlet_id'] : null
        );

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
                'outlet' => $payload['outlet'] ?? 'Main Outlet',
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
        if ($sales <= 0) {
            return null;
        }
        $cash = $this->resolveAccount('cash_bank', ['1100'], ['asset'], $outletId);
        $revenue = $this->resolveAccount('sales_revenue', ['4100'], ['revenue'], $outletId);
        if ($cash === null || $revenue === null) {
            return null;
        }

        $lines = [
            ['account_id' => $cash->id, 'debit' => $sales, 'credit' => 0, 'memo' => 'Payment completion'],
            ['account_id' => $revenue->id, 'debit' => 0, 'credit' => $sales, 'memo' => 'Revenue recognition'],
        ];
        if ($cogs > 0) {
            $cogsAcc = $this->resolveAccount('cogs', ['5100'], ['expense'], $outletId);
            $inventory = $this->resolveAccount('inventory', ['1300'], ['asset'], $outletId);
            if ($cogsAcc !== null && $inventory !== null) {
                $lines[] = ['account_id' => $cogsAcc->id, 'debit' => $cogs, 'credit' => 0, 'memo' => 'COGS recognition'];
                $lines[] = ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $cogs, 'memo' => 'Inventory reduction'];
            }
        }

        return $this->post([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'source_type' => 'order_payment',
            'source_id' => $orderId,
            'journal_date' => now()->toDateString(),
            'description' => 'Auto posting from order payment completion',
            'posting_key' => 'order-payment-'.$orderId,
            'scope' => 'order_payment.'.$orderId,
            'lines' => $lines,
        ]);
    }

    public function postForInventoryMovement(string $type, int $movementId, int $tenantId, ?int $outletId, float $amount): ?Journal
    {
        if ($amount <= 0) {
            return null;
        }
        $inventory = $this->resolveAccount('inventory', ['1300'], ['asset'], $outletId);
        $counter = $type === 'waste'
            ? $this->resolveAccount('waste_expense', ['5200'], ['expense'], $outletId)
            : $this->resolveAccount('stock_adjustment', ['5300'], ['expense', 'revenue'], $outletId);
        if ($inventory === null || $counter === null) {
            return null;
        }

        $isCreditInventory = in_array($type, ['waste', 'sale'], true);
        $lines = [
            ['account_id' => $counter->id, 'debit' => $isCreditInventory ? $amount : 0, 'credit' => $isCreditInventory ? 0 : $amount, 'memo' => 'Inventory '.$type],
            ['account_id' => $inventory->id, 'debit' => $isCreditInventory ? 0 : $amount, 'credit' => $isCreditInventory ? $amount : 0, 'memo' => 'Inventory '.$type],
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
    }

    public function postForCashVariance(int $sessionId, int $tenantId, ?int $outletId, float $variance): ?Journal
    {
        if (abs($variance) < 0.00001) {
            return null;
        }
        $cash = $this->resolveAccount('cash_bank', ['1100'], ['asset'], $outletId);
        $overShort = $this->resolveAccount('cash_variance', ['5400'], ['expense', 'revenue'], $outletId);
        if ($cash === null || $overShort === null) {
            return null;
        }
        $abs = abs($variance);
        $isShort = $variance < 0;
        $lines = [
            ['account_id' => $overShort->id, 'debit' => $isShort ? $abs : 0, 'credit' => $isShort ? 0 : $abs, 'memo' => 'POS cash variance'],
            ['account_id' => $cash->id, 'debit' => $isShort ? 0 : $abs, 'credit' => $isShort ? $abs : 0, 'memo' => 'POS cash variance'],
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
    }

    /** @param list<array{account_id:int|string,debit:float|int|string,credit:float|int|string,memo?:string|null,meta?:array<string,mixed>|null}> $lines */
    private function assertBalancedLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal must have at least two lines.']);
        }
        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $line) {
            $d = (float) $line['debit'];
            $c = (float) $line['credit'];
            if ($d < 0 || $c < 0 || ($d > 0 && $c > 0)) {
                throw ValidationException::withMessages(['lines' => 'Invalid debit/credit line values.']);
            }
            $debit += $d;
            $credit += $c;
        }
        if (round($debit, 2) !== round($credit, 2) || $debit <= 0) {
            throw new UnprocessableEntityHttpException('Journal is not balanced.');
        }
    }

    private function resolveAccount(string $category, array $fallbackCodes, array $types, ?int $outletId): ?Account
    {
        $query = Account::query()->whereIn('type', $types)->where('is_active', true);
        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $byCategory = (clone $query)->where('category', $category)->orderByRaw('outlet_id is null')->first();
        if ($byCategory !== null) {
            return $byCategory;
        }
        foreach ($fallbackCodes as $code) {
            $candidate = (clone $query)->where('code', $code)->orderByRaw('outlet_id is null')->first();
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return (clone $query)->orderBy('id')->first();
    }

    private function generateJournalNo(): string
    {
        return 'JE-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
    }
}
