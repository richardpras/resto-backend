<?php

namespace Tests\Concerns;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingSetting;

trait AccountingRemediationFixture
{
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
        $cash = Account::query()->create([
            'outlet_id' => $outletId,
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'category' => 'cash_bank',
            'is_active' => true,
        ]);
        $revenue = Account::query()->create([
            'outlet_id' => $outletId,
            'code' => '4100',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'category' => 'sales_revenue',
            'is_active' => true,
        ]);
        $cogs = Account::query()->create([
            'outlet_id' => $outletId,
            'code' => '5100',
            'name' => 'COGS',
            'type' => 'expense',
            'subtype' => 'cogs',
            'category' => 'cogs',
            'is_active' => true,
        ]);
        $inventory = Account::query()->create([
            'outlet_id' => $outletId,
            'code' => '1300',
            'name' => 'Inventory',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'category' => 'inventory',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $revenue->id, (int) $cogs->id, (int) $inventory->id];
    }

    protected function setRevenuePostingMode(string $mode, ?int $outletId = null): void
    {
        AccountingSetting::query()->updateOrCreate(
            ['tenant_id' => null, 'outlet_id' => $outletId],
            ['revenue_posting_mode' => $mode],
        );
    }
}
