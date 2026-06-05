<?php

namespace Tests\Concerns;

use App\Models\Modules\Accounting\Domain\Account;

trait PayrollPostingAccountsFixture
{
    protected function seedPayrollPostingAccounts(): void
    {
        $accounts = [
            ['code' => '6100', 'name' => 'Payroll Expense', 'type' => 'expense', 'subtype' => 'expense'],
            ['code' => '2150', 'name' => 'Salary Payable', 'type' => 'liability', 'subtype' => 'short_term_liability'],
            ['code' => '2160', 'name' => 'PPh21 Payable', 'type' => 'liability', 'subtype' => 'short_term_liability'],
            ['code' => '2170', 'name' => 'BPJS Payable', 'type' => 'liability', 'subtype' => 'short_term_liability'],
            ['code' => '1210', 'name' => 'Employee Loan Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1220', 'name' => 'Cash Advance Receivable', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '2180', 'name' => 'Other Payroll Deductions', 'type' => 'liability', 'subtype' => 'short_term_liability'],
        ];

        foreach ($accounts as $row) {
            Account::query()->firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'subtype' => $row['subtype'],
                    'is_active' => true,
                ],
            );
        }
    }
}
