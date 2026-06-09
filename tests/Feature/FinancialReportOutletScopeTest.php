<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class FinancialReportOutletScopeTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_profit_loss_and_trial_balance_share_outlet_scoped_revenue(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Fin-'.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'fin-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        [$cashId, $salesId] = $this->seedReportAccounts((int) $outlet->id);

        $date = now()->format('Y-m-d');
        $this->postJson('/api/v1/journals', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'journalDate' => $date,
            'status' => 'posted',
            'postingKey' => 'fin-scope-'.uniqid(),
            'lines' => [
                ['accountId' => $cashId, 'debit' => 200, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 200],
            ],
        ])->assertCreated();

        $query = 'outletId='.(int) $outlet->id.'&from='.$date.'&to='.$date;
        $pl = $this->getJson('/api/v1/reports/profit-loss?'.$query)->assertOk();
        $tb = $this->getJson('/api/v1/reports/trial-balance?outletId='.(int) $outlet->id.'&to='.$date)->assertOk();

        $plRevenue = (float) $pl->json('data.totalRevenue');
        $salesRow = collect($tb->json('data.rows'))->first(fn (array $row): bool => (int) $row['account']['id'] === $salesId);
        $this->assertNotNull($salesRow);
        $this->assertSame($plRevenue, (float) $salesRow['credit']);
        $tb->assertJsonPath('data.balanced', true);
    }

    /** @return array{0:int,1:int} */
    private function seedReportAccounts(int $outletId): array
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

