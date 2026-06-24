<?php

namespace Tests\Concerns;

use App\Models\Modules\Accounting\Domain\Account;

trait PayrollPostingAccountsFixture
{
    use AccountingPostingMappingsFixture;

    protected function seedPayrollPostingAccounts(): void
    {
        $accounts = [
            ['code' => '6100', 'name' => 'Payroll Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'payroll_expense'],
            ['code' => '2150', 'name' => 'Salary Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'salary_payable'],
            ['code' => '2160', 'name' => 'PPh21 Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'pph21_payable'],
            ['code' => '2170', 'name' => 'BPJS Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'bpjs_payable'],
            ['code' => '1210', 'name' => 'Employee Loan Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => null],
            ['code' => '1220', 'name' => 'Cash Advance Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => null],
            ['code' => '2180', 'name' => 'Other Payroll Deductions', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'other_deductions'],
        ];

        foreach ($accounts as $row) {
            Account::query()->firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'subtype' => $row['subtype'],
                    'category' => $row['category'],
                    'is_active' => true,
                ],
            );
        }

        $this->seedPayrollPostingMappings();
    }
}
