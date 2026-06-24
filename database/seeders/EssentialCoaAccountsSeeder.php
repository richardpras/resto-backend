<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use Illuminate\Database\Seeder;

/**
 * Idempotent: ensures GL accounts required for procurement, POS, and payroll posting exist.
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
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory'],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'accounts_payable'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'gift_card_liability'],
            ['code' => '2135', 'name' => 'Store Credit Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'store_credit_liability'],
            ['code' => '2140', 'name' => 'GRNI', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'grni'],
            ['code' => '2150', 'name' => 'Payroll Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'salary_payable'],
            ['code' => '2160', 'name' => 'PPh21 Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'pph21_payable'],
            ['code' => '2170', 'name' => 'BPJS Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'bpjs_payable'],
            ['code' => '2180', 'name' => 'Other Payroll Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'other_deductions'],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue'],
            ['code' => '4190', 'name' => 'Gift Card Breakage Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'gift_card_breakage'],
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs'],
            ['code' => '5200', 'name' => 'Waste Expense', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'waste_expense'],
            ['code' => '5300', 'name' => 'Inventory Adjustment', 'type' => 'expense', 'subtype' => 'expense', 'category' => 'stock_adjustment'],
            ['code' => '5400', 'name' => 'Cash Over/Short', 'type' => 'expense', 'subtype' => 'operational_expense', 'category' => 'cash_variance'],
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
