<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_unauthorized_report_requests_are_rejected_and_scoped_requests_validated(): void
    {
        $this->getJson('/api/v1/reports/trial-balance')->assertUnauthorized();

        $user = $this->actingAsUserManagementApiAdministrator();
        $outletA = $this->makeOutlet('A');
        $outletB = $this->makeOutlet('B');
        $this->assignUserToOutlets($user, [$outletA->id]);
        [$cashId, $salesId] = $this->seedAccounts((int) $outletA->id);

        $this->postJson('/api/v1/journals', [
            'tenantId' => 1,
            'outletId' => (int) $outletA->id,
            'journalDate' => now()->format('Y-m-d'),
            'status' => 'posted',
            'postingKey' => 'rep-auth-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 150, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 150],
            ],
        ])->assertCreated();

        $allowed = $this->getJson('/api/v1/reports/trial-balance?outletId='.$outletA->id);
        $allowed->assertOk()->assertJsonPath('data.balanced', true);

        $forbidden = $this->getJson('/api/v1/reports/trial-balance?outletId='.$outletB->id);
        $forbidden->assertUnprocessable();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'unauthorized_access_attempt',
            'entity_type' => 'accounting_scope',
            'entity_id' => (int) $outletB->id,
        ]);
    }

    private function makeOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => 'R-'.$name.'-'.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'r-'.$name.'-'.uniqid(),
        ]);
    }

    /** @return array{0:int,1:int} */
    private function seedAccounts(int $outletId): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '11'.random_int(1000, 9999),
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '41'.random_int(1000, 9999),
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }
}
