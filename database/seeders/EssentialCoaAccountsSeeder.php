<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use Illuminate\Database\Seeder;

/**
 * Idempotent: ensures payroll, cash/bank, and QRIS GL accounts exist with categories.
 * Safe to run on databases where TemplateAccountingSeeder skipped due to partial COA.
 */
class EssentialCoaAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank'],
            ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'bank'],
            ['code' => '1111', 'name' => 'Bank BCA', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'bank'],
            ['code' => '1120', 'name' => 'QRIS / E-Wallet Clearing', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank'],
            ['code' => '1210', 'name' => 'Employee Loan Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => null],
            ['code' => '1220', 'name' => 'Cash Advance Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => null],
            ['code' => '2150', 'name' => 'Payroll Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'salary_payable'],
            ['code' => '2160', 'name' => 'PPh21 Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'pph21_payable'],
            ['code' => '2170', 'name' => 'BPJS Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'bpjs_payable'],
            ['code' => '2180', 'name' => 'Other Payroll Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'other_deductions'],
            ['code' => '6100', 'name' => 'Payroll Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'payroll_expense'],
        ];

        foreach ($accounts as $row) {
            $existing = Account::query()->where('code', $row['code'])->first();
            if ($existing === null) {
                Account::query()->create([
                    'tenant_id' => null,
                    'outlet_id' => null,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'subtype' => $row['subtype'],
                    'category' => $row['category'],
                    'is_active' => true,
                ]);

                continue;
            }

            $updates = [
                'name' => $row['name'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'is_active' => true,
            ];
            if ($row['category'] !== null && ($existing->category === null || $existing->category === '')) {
                $updates['category'] = $row['category'];
            }
            $existing->fill($updates);
            $existing->save();
        }
    }
}
