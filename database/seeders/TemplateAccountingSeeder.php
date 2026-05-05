<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds chart of accounts and posted journals from template/src/stores/accountingStore.ts.
 */
class TemplateAccountingSeeder extends Seeder
{
    public function run(): void
    {
        if (Account::query()->exists()) {
            return;
        }

        /** @var AccountingService $svc */
        $svc = app(AccountingService::class);

        $accounts = [
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'subtype' => 'fixed_asset'],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'short_term_liability'],
            ['code' => '2500', 'name' => 'Long-term Loan', 'type' => 'liability', 'subtype' => 'long_term_liability'],
            ['code' => '3100', 'name' => "Owner's Equity", 'type' => 'equity', 'subtype' => 'equity'],
            ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'equity'],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue'],
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '6100', 'name' => 'Salaries Expense', 'type' => 'expense', 'subtype' => 'expense'],
            ['code' => '6200', 'name' => 'Rent Expense', 'type' => 'expense', 'subtype' => 'expense'],
            ['code' => '6300', 'name' => 'Utilities Expense', 'type' => 'expense', 'subtype' => 'expense'],
        ];

        foreach ($accounts as $row) {
            $svc->createAccount([
                'tenant_id' => null,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'is_active' => true,
            ]);
        }

        $byCode = Account::query()->pluck('id', 'code')->all();

        $d = static fn (int $daysAgo) => Carbon::now()->subDays($daysAgo)->toDateString();

        $journals = [
            [
                'journal_no' => 'JE-TPL-INV-001',
                'journal_date' => $d(20),
                'description' => 'Initial capital',
                'outlet' => 'Main Outlet',
                'status' => 'posted',
                'lines' => [
                    ['account_id' => $byCode['1100'], 'debit' => 50000000, 'credit' => 0],
                    ['account_id' => $byCode['3100'], 'debit' => 0, 'credit' => 50000000],
                ],
            ],
            [
                'journal_no' => 'JE-TPL-SALE-001',
                'journal_date' => $d(10),
                'description' => 'Daily sales',
                'outlet' => 'Main Outlet',
                'status' => 'posted',
                'lines' => [
                    ['account_id' => $byCode['1100'], 'debit' => 8500000, 'credit' => 0],
                    ['account_id' => $byCode['4100'], 'debit' => 0, 'credit' => 8500000],
                ],
            ],
            [
                'journal_no' => 'JE-TPL-COGS-001',
                'journal_date' => $d(10),
                'description' => 'COGS for daily sales',
                'outlet' => 'Main Outlet',
                'status' => 'posted',
                'lines' => [
                    ['account_id' => $byCode['5100'], 'debit' => 3200000, 'credit' => 0],
                    ['account_id' => $byCode['1300'], 'debit' => 0, 'credit' => 3200000],
                ],
            ],
            [
                'journal_no' => 'JE-TPL-EXP-001',
                'journal_date' => $d(5),
                'description' => 'Monthly rent',
                'outlet' => 'Main Outlet',
                'status' => 'posted',
                'lines' => [
                    ['account_id' => $byCode['6200'], 'debit' => 5000000, 'credit' => 0],
                    ['account_id' => $byCode['1100'], 'debit' => 0, 'credit' => 5000000],
                ],
            ],
            [
                'journal_no' => 'JE-TPL-SALE-002',
                'journal_date' => $d(3),
                'description' => 'Daily sales',
                'outlet' => 'Main Outlet',
                'status' => 'posted',
                'lines' => [
                    ['account_id' => $byCode['1100'], 'debit' => 6200000, 'credit' => 0],
                    ['account_id' => $byCode['4100'], 'debit' => 0, 'credit' => 6200000],
                ],
            ],
        ];

        foreach ($journals as $payload) {
            $svc->createJournal(array_merge([
                'tenant_id' => null,
                'source_type' => 'demo_seed',
                'source_id' => null,
                'created_by' => null,
            ], $payload));
        }
    }
}
