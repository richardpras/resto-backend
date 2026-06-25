<?php

namespace Database\Seeders\CustomerDemo;

use Database\Seeders\AccountingPostingMappingsSeeder;
use Database\Seeders\EssentialCoaAccountsSeeder;
use Database\Seeders\PaymentBankCoaLinkSeeder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Database\Seeder;

class WrWbMay2026Seeder extends Seeder
{
    public function run(): void
    {
        $this->ensureBaseDependencies();

        $this->call([
            WrWbFoundationSeeder::class,
            WrWbCatalogSeeder::class,
            WrWbPosSeeder::class,
            WrWbProcurementSeeder::class,
            WrWbHrPayrollSeeder::class,
            WrWbAccountingSeeder::class,
            WrWbLoyaltySeeder::class,
        ]);
    }

    private function ensureBaseDependencies(): void
    {
        if (! \App\Models\Modules\UserManagement\Domain\Permission::query()->exists()) {
            $this->call(UserManagementPermissionsSeeder::class);
        }

        $this->call(EssentialCoaAccountsSeeder::class);
        $this->call(AccountingPostingMappingsSeeder::class);
        $this->call(PaymentBankCoaLinkSeeder::class);
    }
}
