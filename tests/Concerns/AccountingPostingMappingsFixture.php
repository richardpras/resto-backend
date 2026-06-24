<?php

namespace Tests\Concerns;

use Database\Seeders\Support\PostingMappingDefaults;
use Illuminate\Support\Facades\DB;

trait AccountingPostingMappingsFixture
{
    /** @param array<string, string> $bankOverrides bankAccountId => account code */
    protected function seedProcurementPostingMappings(?int $outletId = null, array $bankOverrides = []): void
    {
        $this->seedPostingMappingsForModule('procurement', PostingMappingDefaults::procurement(), $outletId, $bankOverrides);
    }

    protected function seedPayrollPostingMappings(?int $outletId = null): void
    {
        $this->seedPostingMappingsForModule('payroll', PostingMappingDefaults::payroll(), $outletId);
    }

    protected function seedPosPostingMappings(?int $outletId = null, array $paymentOverrides = []): void
    {
        $this->seedPostingMappingsForModule('pos', PostingMappingDefaults::pos(), $outletId, [], $paymentOverrides);
    }

    protected function seedInventoryPostingMappings(?int $outletId = null): void
    {
        $this->seedPostingMappingsForModule('inventory', PostingMappingDefaults::inventory(), $outletId);
    }

    protected function seedInventoryPostingAccountsAndMappings(?int $outletId = null): void
    {
        $accounts = [
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '5200', 'name' => 'Waste Expense', 'type' => 'expense', 'subtype' => 'expense'],
            ['code' => '5300', 'name' => 'Inventory Adjustment', 'type' => 'expense', 'subtype' => 'expense'],
        ];

        foreach ($accounts as $row) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'subtype' => $row['subtype'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->seedInventoryPostingMappings($outletId);
    }

    protected function seedPosPostingAccountsAndMappings(?int $outletId = null, array $paymentOverrides = []): void
    {
        $accounts = [
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1110', 'name' => 'Bank Card', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1111', 'name' => 'Bank Transfer', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1120', 'name' => 'QRIS/E-wallet', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'subtype' => 'short_term_liability'],
            ['code' => '2135', 'name' => 'Store Credit Liability', 'type' => 'liability', 'subtype' => 'short_term_liability'],
            ['code' => '4100', 'name' => 'Sales', 'type' => 'revenue', 'subtype' => 'revenue'],
            ['code' => '4190', 'name' => 'Gift Card Breakage Revenue', 'type' => 'revenue', 'subtype' => 'revenue'],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs'],
            ['code' => '5400', 'name' => 'Cash Variance', 'type' => 'expense', 'subtype' => 'operational_expense'],
        ];

        foreach ($accounts as $row) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'subtype' => $row['subtype'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->seedPosPostingMappings($outletId, $paymentOverrides);
    }

    /**
     * @param  array<string, string>  $rules
     * @param  array<string, string>  $bankOverrides
     * @param  array<string, string>  $paymentOverrides
     */
    protected function seedPostingMappingsForModule(
        string $module,
        array $rules,
        ?int $outletId = null,
        array $bankOverrides = [],
        array $paymentOverrides = [],
    ): void {
        $accountIdByCode = static fn (string $code): int => (int) DB::table('accounts')->where('code', $code)->value('id');

        foreach ($rules as $ruleKey => $code) {
            $accountId = $accountIdByCode($code);
            if ($accountId <= 0) {
                continue;
            }

            DB::table('accounting_posting_mappings')->updateOrInsert(
                [
                    'tenant_id' => null,
                    'outlet_id' => $outletId,
                    'module' => $module,
                    'rule_key' => $ruleKey,
                ],
                [
                    'chart_account_id' => $accountId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        foreach ($bankOverrides as $bankAccountId => $code) {
            DB::table('accounting_posting_mappings')->updateOrInsert(
                [
                    'tenant_id' => null,
                    'outlet_id' => $outletId,
                    'module' => $module,
                    'rule_key' => 'procurement.payment.bank.'.$bankAccountId,
                ],
                [
                    'chart_account_id' => $accountIdByCode($code),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        foreach ($paymentOverrides as $paymentMethodCode => $code) {
            DB::table('accounting_posting_mappings')->updateOrInsert(
                [
                    'tenant_id' => null,
                    'outlet_id' => $outletId,
                    'module' => $module,
                    'rule_key' => 'pos.payment.'.$paymentMethodCode,
                ],
                [
                    'chart_account_id' => $accountIdByCode($code),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /** @param array<string, int> $codeToAccountId */
    protected function seedOutletPostingMappingsForModule(
        int $outletId,
        string $module,
        array $rules,
        array $codeToAccountId,
    ): void {
        foreach ($rules as $ruleKey => $code) {
            $accountId = $codeToAccountId[$code] ?? 0;
            if ($accountId <= 0) {
                continue;
            }

            DB::table('accounting_posting_mappings')->updateOrInsert(
                [
                    'tenant_id' => null,
                    'outlet_id' => $outletId,
                    'module' => $module,
                    'rule_key' => $ruleKey,
                ],
                [
                    'chart_account_id' => $accountId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    protected function seedOutletPosMappingsFromAccounts(int $outletId, array $codeToAccountId): void
    {
        $this->seedOutletPostingMappingsForModule(
            $outletId,
            'pos',
            PostingMappingDefaults::pos(),
            $codeToAccountId,
        );
    }
}
