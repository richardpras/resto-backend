<?php

namespace Tests\Concerns;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingSetting;

trait AccountingRemediationFixture
{
    use AccountingPostingMappingsFixture;

    /** @return array{0:\App\Models\User,1:\App\Models\Modules\Settings\Domain\Outlet} */
    protected function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = \App\Models\Modules\Settings\Domain\Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'acct-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    protected function seedPosPostingAccounts(int $outletId): array
    {
        $definitions = [
            '1100' => ['name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank'],
            '4100' => ['name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue'],
            '5100' => ['name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs'],
            '1300' => ['name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory'],
            '1120' => ['name' => 'QRIS', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'bank'],
            '2130' => ['name' => 'Gift Card Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'gift_card_liability'],
            '2135' => ['name' => 'Store Credit Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'store_credit_liability'],
            '5400' => ['name' => 'Cash Variance', 'type' => 'expense', 'subtype' => 'operational_expense', 'category' => 'cash_variance'],
            '4190' => ['name' => 'Gift Card Breakage', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'gift_card_breakage'],
        ];

        $codeToAccountId = [];
        foreach ($definitions as $code => $meta) {
            $account = Account::query()->firstOrCreate(
                ['code' => $code],
                [
                    'outlet_id' => $outletId,
                    'name' => $meta['name'],
                    'type' => $meta['type'],
                    'subtype' => $meta['subtype'],
                    'category' => $meta['category'],
                    'is_active' => true,
                ],
            );
            $codeToAccountId[$code] = (int) $account->id;
        }

        $this->seedOutletPosMappingsFromAccounts($outletId, $codeToAccountId);

        return [
            $codeToAccountId['1100'],
            $codeToAccountId['4100'],
            $codeToAccountId['5100'],
            $codeToAccountId['1300'],
        ];
    }

    protected function setRevenuePostingMode(string $mode, ?int $outletId = null): void
    {
        AccountingSetting::query()->updateOrCreate(
            ['tenant_id' => null, 'outlet_id' => $outletId],
            ['revenue_posting_mode' => $mode],
        );
    }
}
