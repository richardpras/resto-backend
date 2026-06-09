<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class GlBalanceService
{
    public function __construct(
        private readonly AccountingPostingIntegrityService $integrityService,
    ) {}

    public function categoryBalance(string $category, array $fallbackCodes, array $types, ?int $outletId, ?string $to = null): float
    {
        $accountIds = $this->resolveAccountIds($category, $fallbackCodes, $types, $outletId);
        if ($accountIds === []) {
            return 0.0;
        }

        $query = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->where('j.status', 'posted')
            ->whereIn('je.account_id', $accountIds);

        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->whereNull('j.outlet_id')->orWhere('j.outlet_id', $outletId);
            });
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('j.journal_date', '<=', $to);
        }

        $debit = (float) (clone $query)->sum('je.debit');
        $credit = (float) (clone $query)->sum('je.credit');

        $account = Account::query()->whereIn('id', $accountIds)->first();
        $type = $account?->type ?? 'liability';

        return round(in_array($type, ['asset', 'expense'], true) ? ($debit - $credit) : ($credit - $debit), 2);
    }

    /** @return list<int> */
    private function resolveAccountIds(string $category, array $fallbackCodes, array $types, ?int $outletId): array
    {
        $ids = [];
        $primary = $this->integrityService->resolveAccount($category, $fallbackCodes, $types, $outletId);
        if ($primary !== null) {
            $ids[] = (int) $primary->id;
        }

        foreach ($fallbackCodes as $code) {
            $query = Account::query()->where('code', $code)->where('is_active', true);
            if ($outletId !== null && $outletId > 0) {
                $query->where(function ($q) use ($outletId): void {
                    $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
                });
            }
            $account = $query->first();
            if ($account !== null) {
                $ids[] = (int) $account->id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function codeBalance(string $code, string $accountType, ?int $outletId, ?string $to = null): float
    {
        $query = Account::query()->where('code', $code)->where('is_active', true);
        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            });
        }
        $account = $query->first();
        if ($account === null) {
            return 0.0;
        }

        return $this->accountIdsBalance([(int) $account->id], $this->postedJournals($outletId, $to), $accountType);
    }

    public function subtypeBalance(string $subtype, string $accountType, ?int $outletId, ?string $to = null): float
    {
        $query = Account::query()->where('subtype', $subtype)->where('is_active', true);
        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            });
        }
        $accountIds = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($accountIds === []) {
            return 0.0;
        }

        return $this->accountIdsBalance($accountIds, $this->postedJournals($outletId, $to), $accountType);
    }

    /** @return Collection<int, Journal> */
    private function postedJournals(?int $outletId, ?string $to): Collection
    {
        $query = Journal::query()
            ->with(['entries'])
            ->where('status', 'posted');
        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            });
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('journal_date', '<=', $to);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, Journal>  $journals
     */
    public function accountIdsBalance(array $accountIds, Collection $journals, string $accountType): float
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($journals as $journal) {
            foreach ($journal->entries as $entry) {
                if (! in_array((int) $entry->account_id, $accountIds, true)) {
                    continue;
                }
                $debit += (float) $entry->debit;
                $credit += (float) $entry->credit;
            }
        }

        return round(in_array($accountType, ['asset', 'expense'], true) ? ($debit - $credit) : ($credit - $debit), 2);
    }
}
