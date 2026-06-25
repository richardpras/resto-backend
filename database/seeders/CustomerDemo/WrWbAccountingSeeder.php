<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Database\Seeder;

class WrWbAccountingSeeder extends Seeder
{
    public function run(): void
    {
        $outletId = CustomerDemoContext::outletId();
        $byCode = Account::query()->pluck('id', 'code');

        Account::query()->firstOrCreate(
            ['code' => '6110'],
            ['name' => 'Beban Operasional', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'operational_expense', 'is_active' => true],
        );
        $byCode = Account::query()->pluck('id', 'code');

        /** @var AccountingService $accounting */
        $accounting = app(AccountingService::class);

        $journals = [
            [
                'journal_no' => 'WRWB-JE-202605-01',
                'journal_date' => CustomerDemoContext::date(10)->toDateString(),
                'description' => 'Setor tunai ke bank BCA',
                'lines' => [
                    ['account_id' => (int) $byCode['1111'], 'debit' => 5000000, 'credit' => 0],
                    ['account_id' => (int) $byCode['1100'], 'debit' => 0, 'credit' => 5000000],
                ],
            ],
            [
                'journal_no' => 'WRWB-JE-202605-02',
                'journal_date' => CustomerDemoContext::date(15)->toDateString(),
                'description' => 'Biaya admin bank',
                'lines' => [
                    ['account_id' => (int) $byCode['6110'], 'debit' => 65000, 'credit' => 0],
                    ['account_id' => (int) $byCode['1111'], 'debit' => 0, 'credit' => 65000],
                ],
            ],
            [
                'journal_no' => 'WRWB-JE-202605-03',
                'journal_date' => CustomerDemoContext::date(20)->toDateString(),
                'description' => 'Settlement QRIS clearing ke bank',
                'lines' => [
                    ['account_id' => (int) $byCode['1111'], 'debit' => 3200000, 'credit' => 0],
                    ['account_id' => (int) $byCode['1120'], 'debit' => 0, 'credit' => 3200000],
                ],
            ],
        ];

        foreach ($journals as $row) {
            $journal = Journal::query()->where('journal_no', $row['journal_no'])->first();
            if ($journal !== null && $journal->status === 'posted') {
                continue;
            }

            if ($journal === null) {
                $journal = $accounting->createJournal([
                    'tenant_id' => null,
                    'outlet_id' => $outletId,
                    'journal_no' => $row['journal_no'],
                    'source_type' => 'manual',
                    'journal_date' => $row['journal_date'],
                    'description' => $row['description'],
                    'status' => 'draft',
                    'lines' => $row['lines'],
                ]);
            }

            if ($journal->status !== 'posted') {
                $accounting->postJournal($journal);
            }
        }
    }
}
