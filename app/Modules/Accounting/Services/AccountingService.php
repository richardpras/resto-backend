<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AccountingService
{
    public function listAccounts(?int $tenantId = null): Collection
    {
        $query = Account::query()->orderBy('code');

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
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
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'subtype' => $data['subtype'] ?? $this->defaultSubtypeForType($data['type']),
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
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
            'name' => $data['name'] ?? $account->name,
            'type' => $data['type'] ?? $account->type,
            'subtype' => $data['subtype'] ?? $account->subtype ?? $this->defaultSubtypeForType($account->type),
            'parent_id' => array_key_exists('parent_id', $data) ? $data['parent_id'] : $account->parent_id,
            'description' => array_key_exists('description', $data) ? $data['description'] : $account->description,
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

    public function listJournals(?int $tenantId = null): Collection
    {
        $query = Journal::query()
            ->with(['entries' => fn ($q) => $q->orderBy('line_no')])
            ->orderByDesc('journal_date')
            ->orderByDesc('id');

        if ($tenantId !== null && $tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get();
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
        if ($journal->status !== 'draft') {
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
        if ($journal->status !== 'draft') {
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

        $journal->load(['entries']);
        $lines = $journal->entries->map(fn (JournalEntry $e) => [
            'account_id' => $e->account_id,
            'debit' => (float) $e->debit,
            'credit' => (float) $e->credit,
            'memo' => $e->memo,
        ])->all();
        $this->assertBalancedLines($lines);

        $journal->status = 'posted';
        $journal->save();

        return $journal->refresh()->load(['entries' => fn ($q) => $q->orderBy('line_no')]);
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
}
