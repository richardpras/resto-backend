<?php

namespace Tests\Feature;

use Database\Seeders\AccountingPostingMappingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class AccountingPostingMappingsSeederTest extends TestCase
{
    use ProcurementTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccountingAccounts();
    }

    public function test_seeder_inserts_missing_mappings_only(): void
    {
        DB::table('accounting_posting_mappings')->delete();

        $this->assertSame(0, DB::table('accounting_posting_mappings')->count());

        $this->seed(AccountingPostingMappingsSeeder::class);

        $this->assertGreaterThan(0, DB::table('accounting_posting_mappings')->count());
        $this->assertDatabaseHas('accounting_posting_mappings', [
            'module' => 'procurement',
            'rule_key' => 'procurement.grn.inventory',
        ]);
        $this->assertDatabaseHas('accounting_posting_mappings', [
            'module' => 'inventory',
            'rule_key' => 'inventory.asset',
        ]);

        $manualAccountId = (int) DB::table('accounts')->where('code', '1300')->value('id');
        DB::table('accounting_posting_mappings')->insert([
            'tenant_id' => null,
            'outlet_id' => null,
            'module' => 'procurement',
            'rule_key' => 'procurement.grn.inventory',
            'chart_account_id' => $manualAccountId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $differentAccount = DB::table('accounts')->insertGetId([
            'code' => '1399',
            'name' => 'Alt Inventory',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('accounting_posting_mappings')
            ->where('rule_key', 'procurement.grn.inventory')
            ->update(['chart_account_id' => $differentAccount]);

        $this->seed(AccountingPostingMappingsSeeder::class);

        $this->assertSame(
            $differentAccount,
            (int) DB::table('accounting_posting_mappings')
                ->where('rule_key', 'procurement.grn.inventory')
                ->value('chart_account_id'),
            'Seeder must not overwrite manually configured mapping rows.',
        );
    }
}
