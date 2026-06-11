<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\CashierStationValidationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class CashierStationInventoryAccountingTest extends TestCase
{
    use RefreshDatabase;
    use CashierStationValidationFixture;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_cashier_station_item_deducts_inventory_and_posts_revenue_on_payment(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedPosPostingAccounts((int) $outlet->id);
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);

        $stations = $this->provisionCashierValidationStations($outlet);
        $items = $this->createCashierValidationMenuItems($outlet, $stations);
        $ingredient = $this->attachRetailPackRecipe($outlet, $items['rokok'], 50);

        $order = $this->createConfirmedCashierValidationOrder(
            $outlet,
            $items['nasi'],
            $items['esTeh'],
            $items['rokok'],
        );
        $orderId = $order['orderId'];

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'name' => 'Rokok Marlboro',
        ]);

        $this->payCashierValidationOrder($orderId, $order['subtotal']);

        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'stock' => 49,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'type' => 'sale',
            'quantity' => 1,
            'source_type' => 'order_payment',
        ]);

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->value('id');
        $this->assertGreaterThan(0, $journalId);

        $revenueCredit = (float) DB::table('journal_entries as je')
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->where('je.journal_id', $journalId)
            ->where('a.code', '4100')
            ->value('je.credit');
        $this->assertSame($order['subtotal'], $revenueCredit);

        $date = now()->format('Y-m-d');
        $salesResponse = $this->getJson('/api/v1/reports/executive-sales?'.http_build_query([
            'outletId' => $outlet->id,
            'startDate' => $date,
            'endDate' => $date,
        ]))->assertOk();

        $this->assertEquals(
            $order['subtotal'],
            (float) $salesResponse->json('data.summary.netSales'),
        );
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Cashier Inventory Validation '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'cashier-inv-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
