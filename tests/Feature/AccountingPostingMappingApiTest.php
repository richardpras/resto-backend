<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class AccountingPostingMappingApiTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_get_and_patch_posting_mappings_round_trip(): void
    {
        $user = $this->actingAsAccountingUser();
        $inventoryId = (int) DB::table('accounts')->where('code', '1300')->value('id');

        $this->getJson('/api/v1/accounting/posting-mappings?module=procurement')
            ->assertOk()
            ->assertJsonPath('data.module', 'procurement')
            ->assertJsonPath('data.missingRequiredCount', 0);

        $this->patchJson('/api/v1/accounting/posting-mappings', [
            'module' => 'procurement',
            'mappings' => [
                ['ruleKey' => 'procurement.grn.inventory', 'chartAccountId' => $inventoryId],
                ['ruleKey' => 'procurement.grn.grni', 'chartAccountId' => (int) DB::table('accounts')->where('code', '2140')->value('id')],
                ['ruleKey' => 'procurement.invoice.grni', 'chartAccountId' => (int) DB::table('accounts')->where('code', '2140')->value('id')],
                ['ruleKey' => 'procurement.invoice.accounts_payable', 'chartAccountId' => (int) DB::table('accounts')->where('code', '2100')->value('id')],
                ['ruleKey' => 'procurement.payment.accounts_payable', 'chartAccountId' => (int) DB::table('accounts')->where('code', '2100')->value('id')],
                ['ruleKey' => 'procurement.payment.cash', 'chartAccountId' => (int) DB::table('accounts')->where('code', '1100')->value('id')],
                ['ruleKey' => 'procurement.payment.bank', 'chartAccountId' => (int) DB::table('accounts')->where('code', '1110')->value('id')],
            ],
            'bankOverrides' => [
                ['bankAccountId' => 'bank-1', 'chartAccountId' => (int) DB::table('accounts')->where('code', '1111')->value('id')],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.bankOverrides.0.bankAccountId', 'bank-1')
            ->assertJsonPath('data.bankOverrides.0.chartAccountCode', '1111');

        $this->assertDatabaseHas('accounting_posting_mappings', [
            'module' => 'procurement',
            'rule_key' => 'procurement.payment.bank.bank-1',
        ]);

        Passport::actingAs($user);
        $this->getJson('/api/v1/accounting/posting-mappings/status?module=procurement')
            ->assertOk()
            ->assertJsonPath('data.missingRequiredCount', 0);
    }

    private function actingAsAccountingUser(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_accounting_user__'],
            ['description' => 'Test fixture: accounting access'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['accounting.manage'])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'accounting-fixture-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }
}
