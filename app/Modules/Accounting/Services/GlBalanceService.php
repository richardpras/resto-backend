<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Support\Collection;
final class GlBalanceService
{
    public function __construct(
        private readonly AccountingPostingMappingService $postingMappingService,
    ) {}

    public function mappedRuleBalance(
        ?int $tenantId,
        ?int $outletId,
        string $module,
        string $ruleKey,
        ?string $to = null,
    ): float {
        $resolvedOutletId = (int) ($outletId ?? 0);
        $accountId = $this->postingMappingService->resolveAccountIdOrFail(
            $tenantId,
            $resolvedOutletId,
            $module,
            $ruleKey,
        );
        $account = Account::query()->findOrFail($accountId);

        return $this->accountIdsBalance([(int) $account->id], $this->postedJournals($outletId, $to), (string) $account->type);
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
