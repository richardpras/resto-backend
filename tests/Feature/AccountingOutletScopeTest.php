<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingOutletScopeTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_journal_and_account_queries_are_outlet_scoped(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outletA = $this->makeOutlet('A');
        $outletB = $this->makeOutlet('B');
        $this->assignUserToOutlets($user, [$outletA->id]);

        $this->seedAccount((int) $outletA->id, 'A');
        $this->seedAccount((int) $outletB->id, 'B');

        $accounts = $this->getJson('/api/v1/accounts?outletId='.$outletA->id);
        $accounts->assertOk();
        $this->assertCount(1, $accounts->json('data'));

        $outOfScopeAccounts = $this->getJson('/api/v1/accounts?outletId='.$outletB->id);
        $outOfScopeAccounts->assertUnprocessable();

        $journals = $this->getJson('/api/v1/journals?outletId='.$outletB->id);
        $journals->assertUnprocessable();
    }

    private function makeOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => 'O-'.$name.'-'.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'o-'.$name.'-'.uniqid(),
        ]);
    }

    private function seedAccount(int $outletId, string $suffix): void
    {
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '1'.$suffix.random_int(100, 999),
            'name' => 'Cash-'.$suffix,
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
    }
}
