<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds categorized chart of accounts for auto-posting (ACCOUNTING-REMEDIATION-02).
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
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank'],
            ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'bank'],
            ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'accounts_receivable'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory'],
            ['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'subtype' => 'fixed_asset', 'category' => 'fixed_asset'],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'accounts_payable'],
            ['code' => '2140', 'name' => 'GRNI', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'grni'],
            ['code' => '2150', 'name' => 'Payroll Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'salary_payable'],
            ['code' => '2160', 'name' => 'PPh21 Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'pph21_payable'],
            ['code' => '2170', 'name' => 'BPJS Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'bpjs_payable'],
            ['code' => '2180', 'name' => 'Other Payroll Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'other_deductions'],
            ['code' => '2500', 'name' => 'Long-term Loan', 'type' => 'liability', 'subtype' => 'long_term_liability', 'category' => 'long_term_loan'],
            ['code' => '3100', 'name' => "Owner's Equity", 'type' => 'equity', 'subtype' => 'equity', 'category' => 'equity'],
            ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'equity', 'category' => 'retained_earnings'],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue'],
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs'],
            ['code' => '5200', 'name' => 'Waste Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'waste_expense'],
            ['code' => '5300', 'name' => 'Inventory Adjustment', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'stock_adjustment'],
            ['code' => '5400', 'name' => 'Cash Over/Short', 'type' => 'expense', 'subtype' => 'operational_expense', 'category' => 'cash_variance'],
            ['code' => '6100', 'name' => 'Payroll Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'payroll_expense'],
            ['code' => '6200', 'name' => 'Rent Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'rent_expense'],
            ['code' => '6300', 'name' => 'Utilities Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'utilities_expense'],
        ];

        foreach ($accounts as $row) {
            $svc->createAccount([
                'tenant_id' => null,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'category' => $row['category'],
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
                'status' => 'draft',
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
                'status' => 'draft',
                'lines' => [
                    ['account_id' => $byCode['1100'], 'debit' => 8500000, 'credit' => 0],
                    ['account_id' => $byCode['4100'], 'debit' => 0, 'credit' => 8500000],
                ],
            ],
        ];

        foreach ($journals as $journal) {
            $svc->createJournal($journal);
            $created = \App\Models\Modules\Accounting\Domain\Journal::query()->where('journal_no', $journal['journal_no'])->first();
            if ($created !== null && ($journal['status'] ?? 'draft') === 'posted') {
                $svc->postJournal($created);
            }
        }
    }
}
