<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AccountingService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosAuditLogService $auditLogService,
        private readonly AccountingPeriodService $periodService,
    ) {}

    public function listAccounts(?int $tenantId = null, ?User $actor = null, ?int $outletId = null): Collection
    {
        $query = Account::query()->orderBy('code');

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($actor !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($actor);
            if ($outletId !== null) {
                $this->assertOutletAllowedForActor($actor, $outletId);
                $query->where(function ($q) use ($outletId): void {
                    $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
                });
            } else {
                $query->where(function ($q) use ($allowed): void {
                    $q->whereNull('outlet_id')->orWhereIn('outlet_id', $allowed === [] ? [-1] : $allowed);
                });
            }
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAccount(array $data): Account
    {
        return Account::query()->create([
            'tenant_id' => $data['tenant_id'] ?? null,
            'outlet_id' => $data['outlet_id'] ?? null,
            'scope' => $data['scope'] ?? (($data['outlet_id'] ?? null) ? 'outlet' : 'global'),
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'category' => $data['category'] ?? null,
            'subtype' => $data['subtype'] ?? $this->defaultSubtypeForType($data['type']),
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'config' => $data['config'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(Account $account, array $data): Account
    {
        $account->fill([
            'code' => $data['code'] ?? $account->code,
            'outlet_id' => array_key_exists('outlet_id', $data) ? $data['outlet_id'] : $account->outlet_id,
            'scope' => $data['scope'] ?? $account->scope ?? (($account->outlet_id ?? null) ? 'outlet' : 'global'),
            'name' => $data['name'] ?? $account->name,
            'type' => $data['type'] ?? $account->type,
            'category' => array_key_exists('category', $data) ? $data['category'] : $account->category,
            'subtype' => $data['subtype'] ?? $account->subtype ?? $this->defaultSubtypeForType($account->type),
            'parent_id' => array_key_exists('parent_id', $data) ? $data['parent_id'] : $account->parent_id,
            'description' => array_key_exists('description', $data) ? $data['description'] : $account->description,
            'config' => array_key_exists('config', $data) ? $data['config'] : $account->config,
            'is_active' => $data['is_active'] ?? $account->is_active,
        ]);
        $account->save();

        return $account->refresh();
    }

    public function deleteAccount(Account $account): void
    {
        if (JournalEntry::query()->where('account_id', $account->id)->exists()) {
            throw new ConflictHttpException('Account is referenced by journal lines and cannot be deleted.');
        }

        $account->delete();
    }

    public function listJournals(?int $tenantId = null, ?User $actor = null, ?int $outletId = null): Collection
    {
        $query = Journal::query()
            ->with(['entries' => fn ($q) => $q->orderBy('line_no')])
            ->orderByDesc('journal_date')
            ->orderByDesc('id');

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($actor !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($actor);
            if ($outletId !== null) {
                $this->assertOutletAllowedForActor($actor, $outletId);
                $query->where(function ($q) use ($outletId): void {
                    $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
                });
            } else {
                $query->where(function ($q) use ($allowed): void {
                    $q->whereNull('outlet_id')->orWhereIn('outlet_id', $allowed === [] ? [-1] : $allowed);
                });
            }
        }

        return $query->get();
    }

    public function findJournalOrFailForActor(int $journalId, ?User $actor): Journal
    {
        $query = Journal::query()->whereKey($journalId);
        if ($actor !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($actor);
            $query->where(function ($q) use ($allowed): void {
                $q->whereNull('outlet_id')->orWhereIn('outlet_id', $allowed === [] ? [-1] : $allowed);
            });
        }
        $journal = $query->first();
        if (! $journal instanceof Journal) {
            if ($actor !== null) {
                $this->auditLogService->log('unauthorized_access_attempt', 'journal', $journalId, null, $actor);
            }
            abort(404, 'Journal not found');
        }

        return $journal;
    }

    public function assertOutletAllowedForActor(User $actor, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($actor);
        if (! in_array($outletId, $allowed, true)) {
            $this->auditLogService->log('unauthorized_access_attempt', 'accounting_scope', $outletId, $outletId, $actor);
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createJournal(array $data): Journal
    {
        $lines = $data['lines'];
        $this->assertBalancedLines($lines);

        return DB::transaction(function () use ($data, $lines): Journal {
            $journal = Journal::query()->create([
                'tenant_id' => $data['tenant_id'] ?? null,
                'outlet_id' => $data['outlet_id'] ?? null,
                'journal_no' => $data['journal_no'] ?? $this->generateJournalNo(),
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'journal_date' => $data['journal_date'],
                'status' => $data['status'] ?? 'draft',
                'description' => $data['description'] ?? null,
                'outlet' => $data['outlet'] ?? 'Main Outlet',
                'created_by' => $data['created_by'] ?? null,
            ]);

            $this->syncJournalLines($journal, $lines);

            return $journal->load(['entries' => fn ($q) => $q->orderBy('line_no')]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateJournal(Journal $journal, array $data): Journal
    {
        if ($journal->status !== 'draft' || (bool) ($journal->immutable ?? false)) {
            throw new UnprocessableEntityHttpException('Only draft journals can be updated.');
        }

        $lines = $data['lines'] ?? null;
        if ($lines !== null) {
            $this->assertBalancedLines($lines);
        }

        return DB::transaction(function () use ($journal, $data, $lines): Journal {
            $journal->fill([
                'journal_date' => $data['journal_date'] ?? $journal->journal_date->format('Y-m-d'),
                'description' => array_key_exists('description', $data) ? $data['description'] : $journal->description,
                'outlet' => array_key_exists('outlet', $data) ? $data['outlet'] : $journal->outlet,
            ]);
            $journal->save();

            if (is_array($lines)) {
                $journal->entries()->delete();
                $this->syncJournalLines($journal, $lines);
            }

            return $journal->refresh()->load(['entries' => fn ($q) => $q->orderBy('line_no')]);
        });
    }

    public function deleteJournal(Journal $journal): void
    {
        if ($journal->status !== 'draft' || (bool) ($journal->immutable ?? false)) {
            throw new UnprocessableEntityHttpException('Only draft journals can be deleted.');
        }

        DB::transaction(function () use ($journal): void {
            $journal->entries()->delete();
            $journal->delete();
        });
    }

    public function postJournal(Journal $journal): Journal
    {
        if ($journal->status !== 'draft') {
            throw new UnprocessableEntityHttpException('Only draft journals can be posted.');
        }
        $this->periodService->assertDateOpen(
            $journal->journal_date->format('Y-m-d'),
            $journal->tenant_id !== null ? (int) $journal->tenant_id : null,
            $journal->outlet_id !== null ? (int) $journal->outlet_id : null
        );

        $journal->load(['entries']);
        $lines = $journal->entries->map(fn (JournalEntry $e) => [
            'account_id' => $e->account_id,
            'debit' => (float) $e->debit,
            'credit' => (float) $e->credit,
            'memo' => $e->memo,
        ])->all();
        $this->assertBalancedLines($lines);

        $journal->status = 'posted';
        $journal->immutable = true;
        $journal->posted_at = now();
        $journal->save();

        return $journal->refresh()->load(['entries' => fn ($q) => $q->orderBy('line_no')]);
    }

    /**
     * @return array{rows:list<array{account:array{id:string,code:string,name:string,type:string,subtype:string,parentId:?string,description:?string,active:bool},debit:float,credit:float,balance:float}>,totalDebit:float,totalCredit:float,balanced:bool}
     */
    public function buildTrialBalanceReport(?string $to = null, ?int $outletId = null, ?int $tenantId = null): array
    {
        $accounts = $this->listAccounts($tenantId);
        if ($outletId !== null && $outletId > 0) {
            $accounts = $accounts->filter(fn (Account $a): bool => $a->outlet_id === null || (int) $a->outlet_id === $outletId)->values();
        }
        $posted = $this->listPostedJournalsForReports($tenantId, null, null, $to);
        if ($outletId !== null && $outletId > 0) {
            $posted = $posted->filter(fn (Journal $j): bool => $j->outlet_id === null || (int) $j->outlet_id === $outletId)->values();
        }

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($accounts as $account) {
            $debit = 0.0;
            $credit = 0.0;
            foreach ($posted as $journal) {
                foreach ($journal->entries as $entry) {
                    if ((string) $entry->account_id !== (string) $account->id) {
                        continue;
                    }
                    $debit += (float) $entry->debit;
                    $credit += (float) $entry->credit;
                }
            }
            if (round($debit, 2) === 0.0 && round($credit, 2) === 0.0) {
                continue;
            }
            $rows[] = [
                'account' => $this->mapAccountForReport($account),
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'balance' => round(in_array($account->type, ['asset', 'expense'], true) ? ($debit - $credit) : ($credit - $debit), 2),
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return [
            'rows' => $rows,
            'totalDebit' => round($totalDebit, 2),
            'totalCredit' => round($totalCredit, 2),
            'balanced' => round($totalDebit, 2) === round($totalCredit, 2),
        ];
    }

    /**
     * @return array{
     *   account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}|null,
     *   rows: list<array{id:string, date:string, reference:string, description:string, debit:float, credit:float, balance:float}>,
     *   opening: float,
     *   closing: float
     * }
     */
    public function buildLedgerReport(string $accountId, ?string $from, ?string $to, ?string $outlet = null, ?int $tenantId = null): array
    {
        $account = Account::query()->find($accountId);
        if (! $account instanceof Account) {
            return ['account' => null, 'rows' => [], 'opening' => 0.0, 'closing' => 0.0];
        }

        $allPosted = $this->listPostedJournalsForReports($tenantId, $outlet, null, null);
        $opening = 0.0;
        if ($from !== null && $from !== '') {
            $openingRows = $allPosted->filter(function (Journal $j) use ($from): bool {
                return $j->journal_date instanceof \DateTimeInterface
                    ? $j->journal_date->format('Y-m-d') < $from
                    : (string) $j->journal_date < $from;
            });
            $opening = $this->accountBalance($account->id, $openingRows, $account->type);
        }

        $periodPosted = $this->listPostedJournalsForReports($tenantId, $outlet, $from, $to);
        $rows = [];
        $running = $opening;

        foreach ($periodPosted as $journal) {
            foreach ($journal->entries as $entry) {
                if ((string) $entry->account_id !== (string) $account->id) {
                    continue;
                }

                $debit = (float) $entry->debit;
                $credit = (float) $entry->credit;
                $delta = in_array($account->type, ['asset', 'expense'], true)
                    ? ($debit - $credit)
                    : ($credit - $debit);
                $running += $delta;

                $rows[] = [
                    'id' => (string) $entry->id,
                    'date' => $journal->journal_date instanceof \DateTimeInterface
                        ? $journal->journal_date->format('Y-m-d')
                        : (string) $journal->journal_date,
                    'reference' => $journal->journal_no ?? '',
                    'description' => $journal->description ?? '',
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $running,
                ];
            }
        }

        return [
            'account' => $this->mapAccountForReport($account),
            'rows' => $rows,
            'opening' => $opening,
            'closing' => $running,
        ];
    }

    /**
     * @return array{
     *   revenue: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   cogs: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   expenses: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   totalRevenue: float,
     *   totalCOGS: float,
     *   grossProfit: float,
     *   totalExpenses: float,
     *   netProfit: float
     * }
     */
    public function buildProfitLossReport(?string $from, ?string $to, ?string $outlet = null, ?int $tenantId = null): array
    {
        $accounts = $this->listAccounts($tenantId);
        $posted = $this->listPostedJournalsForReports($tenantId, $outlet, $from, $to);

        $revenue = [];
        $cogs = [];
        $expenses = [];
        foreach ($accounts as $account) {
            $amount = $this->accountBalance($account->id, $posted, $account->type);
            $row = ['account' => $this->mapAccountForReport($account), 'amount' => $amount];
            if ($account->type === 'revenue') {
                $revenue[] = $row;
                continue;
            }
            if ($account->subtype === 'cogs') {
                $cogs[] = $row;
                continue;
            }
            if ($account->type === 'expense' && $account->subtype !== 'cogs') {
                $expenses[] = $row;
            }
        }

        $totalRevenue = array_reduce($revenue, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0);
        $totalCOGS = array_reduce($cogs, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0);
        $grossProfit = $totalRevenue - $totalCOGS;
        $totalExpenses = array_reduce($expenses, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0);
        $netProfit = $grossProfit - $totalExpenses;

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'expenses' => $expenses,
            'totalRevenue' => $totalRevenue,
            'totalCOGS' => $totalCOGS,
            'grossProfit' => $grossProfit,
            'totalExpenses' => $totalExpenses,
            'netProfit' => $netProfit,
        ];
    }

    /**
     * @return array{
     *   currentAssets: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   fixedAssets: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   shortLiab: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   longLiab: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   equity: list<array{account: array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}, amount: float}>,
     *   totalAssets: float,
     *   totalLiabilities: float,
     *   totalEquity: float,
     *   netProfit: float,
     *   balanced: bool
     * }
     */
    public function buildBalanceSheetReport(?string $to, ?string $outlet = null, ?int $tenantId = null): array
    {
        $accounts = $this->listAccounts($tenantId);
        $posted = $this->listPostedJournalsForReports($tenantId, $outlet, null, $to);

        $group = function (string $subtype) use ($accounts, $posted): array {
            $rows = [];
            foreach ($accounts as $account) {
                if ($account->subtype !== $subtype) {
                    continue;
                }
                $rows[] = [
                    'account' => $this->mapAccountForReport($account),
                    'amount' => $this->accountBalance($account->id, $posted, $account->type),
                ];
            }

            return $rows;
        };

        $currentAssets = $group('current_asset');
        $fixedAssets = $group('fixed_asset');
        $shortLiab = $group('short_term_liability');
        $longLiab = $group('long_term_liability');
        $equity = $group('equity');

        $pl = $this->buildProfitLossReport(null, $to, $outlet, $tenantId);

        $totalAssets = array_reduce($currentAssets, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0)
            + array_reduce($fixedAssets, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0);
        $totalLiabilities = array_reduce($shortLiab, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0)
            + array_reduce($longLiab, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0);
        $totalEquity = array_reduce($equity, fn (float $s, array $x): float => $s + (float) $x['amount'], 0.0)
            + (float) $pl['netProfit'];

        $diff = abs($totalAssets - ($totalLiabilities + $totalEquity));
        $balanced = $diff <= 0.01;
        if (! $balanced) {
            Log::warning('Accounting balance sheet out of balance.', [
                'totalAssets' => $totalAssets,
                'totalLiabilities' => $totalLiabilities,
                'totalEquity' => $totalEquity,
                'difference' => $diff,
                'tolerance' => 0.01,
                'outlet' => $outlet,
                'to' => $to,
                'tenantId' => $tenantId,
            ]);
            if ((bool) config('app.debug')) {
                Log::debug('Balance sheet debug details', [
                    'currentAssets' => $currentAssets,
                    'fixedAssets' => $fixedAssets,
                    'shortLiab' => $shortLiab,
                    'longLiab' => $longLiab,
                    'equity' => $equity,
                ]);
            }
        }

        return [
            'currentAssets' => $currentAssets,
            'fixedAssets' => $fixedAssets,
            'shortLiab' => $shortLiab,
            'longLiab' => $longLiab,
            'equity' => $equity,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'totalEquity' => $totalEquity,
            'netProfit' => (float) $pl['netProfit'],
            'balanced' => $balanced,
        ];
    }

    /**
     * @param  list<array{account_id: int|string, debit: float|int|string, credit: float|int|string, memo?: string|null}>  $lines
     */
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
            if ($d < 0 || $c < 0) {
                throw ValidationException::withMessages(['lines' => 'Debit and credit amounts must be zero or positive.']);
            }
            if ($d > 0 && $c > 0) {
                throw ValidationException::withMessages(['lines' => 'A line cannot have both debit and credit.']);
            }
            $debit += $d;
            $credit += $c;
        }

        if (round($debit, 2) !== round($credit, 2) || $debit <= 0) {
            throw ValidationException::withMessages(['lines' => 'Total debits must equal total credits and be greater than zero.']);
        }
    }

    /**
     * @param  list<array{account_id: int|string, debit: float|int|string, credit: float|int|string, memo?: string|null}>  $lines
     */
    private function syncJournalLines(Journal $journal, array $lines): void
    {
        $lineNo = 1;

        foreach ($lines as $line) {
            JournalEntry::query()->create([
                'journal_id' => $journal->id,
                'account_id' => (int) $line['account_id'],
                'debit' => (float) $line['debit'],
                'credit' => (float) $line['credit'],
                'memo' => $line['memo'] ?? null,
                'line_no' => $lineNo++,
            ]);
        }
    }

    private function generateJournalNo(): string
    {
        return 'JE-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
    }

    private function defaultSubtypeForType(string $type): string
    {
        return match ($type) {
            'asset' => 'current_asset',
            'liability' => 'short_term_liability',
            'equity' => 'equity',
            'revenue' => 'revenue',
            'expense' => 'expense',
            default => 'expense',
        };
    }

    /**
     * @return array{id:string, code:string, name:string, type:string, subtype:string, parentId:?string, description:?string, active:bool}
     */
    private function mapAccountForReport(Account $account): array
    {
        return [
            'id' => (string) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype ?? $this->defaultSubtypeForType($account->type),
            'parentId' => $account->parent_id !== null ? (string) $account->parent_id : null,
            'description' => $account->description,
            'active' => (bool) $account->is_active,
        ];
    }

    /**
     * @return Collection<int, Journal>
     */
    private function listPostedJournalsForReports(
        ?int $tenantId,
        ?string $outlet,
        ?string $from,
        ?string $to
    ): Collection {
        $query = Journal::query()
            ->with(['entries' => fn ($q) => $q->orderBy('line_no')])
            ->where('status', 'posted')
            ->orderBy('journal_date')
            ->orderBy('id');

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($outlet !== null && $outlet !== '' && $outlet !== 'all') {
            $query->where('outlet', $outlet);
        }
        if ($from !== null && $from !== '') {
            $query->whereDate('journal_date', '>=', Carbon::parse($from)->toDateString());
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('journal_date', '<=', Carbon::parse($to)->toDateString());
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Journal>  $journals
     */
    private function accountBalance(int|string $accountId, Collection $journals, string $accountType): float
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($journals as $journal) {
            foreach ($journal->entries as $entry) {
                if ((string) $entry->account_id !== (string) $accountId) {
                    continue;
                }
                $debit += (float) $entry->debit;
                $credit += (float) $entry->credit;
            }
        }

        return in_array($accountType, ['asset', 'expense'], true) ? ($debit - $credit) : ($credit - $debit);
    }
}
