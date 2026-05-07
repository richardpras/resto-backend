<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase8AccountingIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_journal_posting_keeps_double_entry_and_is_idempotent(): void
    {
        [$cashId, $salesId] = $this->seedBasicAccounts();
        $date = now()->format('Y-m-d');

        $first = $this->postJson('/api/v1/journals', [
            'journalDate' => $date,
            'description' => 'Phase 8 posting',
            'status' => 'posted',
            'postingKey' => 'p8-jrn-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 100000, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 100000],
            ],
        ]);
        $first->assertCreated();
        $journalId = (int) $first->json('data.id');

        $duplicate = $this->postJson('/api/v1/journals', [
            'journalDate' => $date,
            'description' => 'Phase 8 posting',
            'status' => 'posted',
            'postingKey' => 'p8-jrn-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 100000, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 100000],
            ],
        ]);
        $duplicate->assertCreated()->assertJsonPath('data.id', (string) $journalId);

        $sumDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $sumCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame(round($sumDebit, 2), round($sumCredit, 2));
    }

    public function test_payment_completion_posts_revenue_and_cogs_using_stock_ledger_cost(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P8 Main');
        [$cashId, $salesId, $cogsId, $inventoryId] = $this->seedPostingAccounts((int) $outlet->id);

        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'P8 Protein',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'stock' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = (int) DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'P8 Menu',
            'price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuId,
            'inventory_item_id' => $ingredient->id,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'P8-T1',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => (int) $user->id,
            'status' => 'open',
            'opening_cash' => 10000,
            'opened_at' => now(),
        ]);

        $orderResp = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'code' => 'P8-ORD-1',
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => (string) $menuId, 'name' => 'P8 Menu', 'qty' => 3, 'price' => 100],
            ],
            'subtotal' => 300,
            'tax' => 0,
            'total' => 300,
            'payments' => [],
        ]);
        $orderResp->assertCreated();
        $orderId = (int) $orderResp->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 300]],
        ])->assertOk();

        $this->assertDatabaseHas('journals', ['source_type' => 'order_payment', 'source_id' => $orderId, 'status' => 'posted']);
        $journalId = (int) DB::table('journals')->where('source_type', 'order_payment')->where('source_id', $orderId)->value('id');
        $this->assertDatabaseHas('journal_entries', ['journal_id' => $journalId, 'account_id' => $cashId, 'debit' => 300.00]);
        $this->assertDatabaseHas('journal_entries', ['journal_id' => $journalId, 'account_id' => $salesId, 'credit' => 300.00]);
        $this->assertDatabaseHas('journal_entries', ['journal_id' => $journalId, 'account_id' => $cogsId, 'debit' => 6.00]);
        $this->assertDatabaseHas('journal_entries', ['journal_id' => $journalId, 'account_id' => $inventoryId, 'credit' => 6.00]);
    }

    public function test_trial_balance_endpoint_is_balanced_and_scoped(): void
    {
        [$user, $outletA] = $this->actAsAdminWithOutlet('P8-A');
        $outletB = Outlet::query()->create([
            'name' => 'P8-B',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p8-b-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outletA->id]);

        [$cashA, $salesA] = $this->seedBasicAccounts((int) $outletA->id);
        [$cashB, $salesB] = $this->seedBasicAccounts((int) $outletB->id, 'B');

        $this->postJson('/api/v1/journals', [
            'journalDate' => now()->format('Y-m-d'),
            'status' => 'posted',
            'outletId' => (int) $outletA->id,
            'lines' => [
                ['accountId' => $cashA, 'debit' => 100, 'credit' => 0],
                ['accountId' => $salesA, 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated();
        $this->postJson('/api/v1/journals', [
            'journalDate' => now()->format('Y-m-d'),
            'status' => 'posted',
            'outletId' => (int) $outletB->id,
            'lines' => [
                ['accountId' => $cashB, 'debit' => 200, 'credit' => 0],
                ['accountId' => $salesB, 'debit' => 0, 'credit' => 200],
            ],
        ])->assertCreated();

        $resp = $this->getJson('/api/v1/reports/trial-balance?outletId='.$outletA->id);
        $resp->assertOk();
        $resp->assertJsonPath('data.totalDebit', 100);
        $resp->assertJsonPath('data.totalCredit', 100);
    }

    /** @return array{0:int,1:int} */
    private function seedBasicAccounts(?int $outletId = null, string $suffix = ''): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => $outletId ? 'outlet' : 'global',
            'category' => 'cash_bank',
            'code' => '1100'.$suffix,
            'name' => 'Cash '.$suffix,
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => $outletId ? 'outlet' : 'global',
            'category' => 'sales_revenue',
            'code' => '4100'.$suffix,
            'name' => 'Sales '.$suffix,
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function seedPostingAccounts(int $outletId): array
    {
        [$cash, $sales] = $this->seedBasicAccounts($outletId);
        $cogs = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'cogs',
            'code' => '5100',
            'name' => 'COGS',
            'type' => 'expense',
            'subtype' => 'cogs',
            'is_active' => true,
        ]);
        $inventory = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'inventory',
            'code' => '1300',
            'name' => 'Inventory',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);

        return [$cash, $sales, (int) $cogs->id, (int) $inventory->id];
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p8-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
