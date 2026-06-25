<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\Concerns\PrinterStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosPrintReprintPlanTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use PrinterStationTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_sync_splits_replaces_open_splits_with_valid_allocation(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Sync Split Outlet');
        $orderId = $this->seedUnpaidOrder((int) $outlet->id, (int) $user->id);
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $response = $this->postJson("/api/v1/orders/{$orderId}/splits/sync", [
            'persons' => [
                [
                    'splitType' => 'by_item',
                    'label' => 'Guest A',
                    'items' => [['orderItemId' => $orderItemId, 'qty' => 1, 'amount' => 50]],
                ],
                [
                    'splitType' => 'by_item',
                    'label' => 'Guest B',
                    'items' => [['orderItemId' => $orderItemId, 'qty' => 2, 'amount' => 100]],
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, OrderSplit::query()->where('order_id', $orderId)->count());
    }

    public function test_incremental_split_payment_queues_receipt_for_paid_guest_only(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Incremental Split');
        $this->seedPosPostingAccountsAndMappings((int) $outlet->id);
        $orderId = $this->seedUnpaidOrder((int) $outlet->id, (int) $user->id, qty: 3, unitPrice: 40);
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $sync = $this->postJson("/api/v1/orders/{$orderId}/splits/sync", [
            'persons' => [
                [
                    'splitType' => 'by_item',
                    'label' => 'Guest A',
                    'items' => [['orderItemId' => $orderItemId, 'qty' => 2, 'amount' => 80]],
                ],
                [
                    'splitType' => 'by_item',
                    'label' => 'Guest B',
                    'items' => [['orderItemId' => $orderItemId, 'qty' => 1, 'amount' => 40]],
                ],
            ],
        ])->assertOk();

        $splitAId = (int) $sync->json('data.0.id');
        $splitBId = (int) $sync->json('data.1.id');

        Queue::fake();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'cash',
                'amount' => 80,
                'orderSplitId' => $splitAId,
                'allocations' => [['orderItemId' => $orderItemId, 'qty' => 2, 'amount' => 80]],
            ]],
        ])->assertOk();

        $this->assertSame(
            1,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->where('order_split_id', $splitAId)
                ->count()
        );
        $this->assertSame(
            0,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->where('order_split_id', $splitBId)
                ->count()
        );
    }

    public function test_customer_bill_render_for_unpaid_order(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Customer Bill');
        $orderId = $this->seedUnpaidOrder((int) $outlet->id, (int) $user->id);

        Queue::fake();

        $response = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_bill',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'issueFiscal' => false,
            'queuePrint' => false,
        ]);

        $response->assertOk();
        $history = ReceiptRenderHistory::query()->where('source_id', $orderId)->firstOrFail();
        $this->assertSame('customer_bill', (string) $history->kind);
        $thermal = (string) $history->thermal_text;
        $this->assertStringContainsString('BILL', $thermal);
        $this->assertStringContainsString('NOT PAID', $thermal);
        $this->assertStringContainsString('Balance Due', $thermal);
        $this->assertDoesNotMatchRegularExpression('/\bCash\s+[\d,]/', $thermal);
    }

    public function test_kitchen_reprint_creates_new_print_jobs(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\Print\ProcessPrintJob::class]);
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-reprint', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);
        $menuItem = $this->createMenuItemForStation($outlet, 'Burger', $stations['kitchen']);

        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'KR-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => $menuItem->name, 'qty' => 2, 'price' => 25000],
            ],
            'subtotal' => 50000,
            'tax' => 0,
            'total' => 50000,
            'payments' => [],
            'confirmedAt' => now()->toISOString(),
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $initialKitchenJobs = PrintJob::query()->where('type', 'kitchen')->where('source_id', $orderId)->count();
        $this->assertGreaterThanOrEqual(1, $initialKitchenJobs);

        $reprint = $this->postJson("/api/v1/orders/{$orderId}/kitchen-reprint", [
            'orderItemIds' => [$orderItemId],
        ]);
        $reprint->assertOk();
        $this->assertNotEmpty($reprint->json('data.printJobIds'));

        $afterReprint = PrintJob::query()->where('type', 'kitchen')->where('source_id', $orderId)->count();
        $this->assertGreaterThan($initialKitchenJobs, $afterReprint);
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'plan-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Kitchen Reprint Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'kr-'.uniqid(),
        ]);
    }

    private function seedUnpaidOrder(int $outletId, int $userId, int $qty = 3, float $unitPrice = 50): int
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Plan Ingredient '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1.5,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => $outletId,
            'stock' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Plan Menu '.uniqid(),
            'category' => 'main',
            'price' => $unitPrice,
            'available' => true,
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => (int) $menuItem->id,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'PLAN-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $userId,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
        $total = $qty * $unitPrice;

        $resp = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'PLAN-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => 'Plan Menu', 'qty' => $qty, 'price' => $unitPrice],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]);
        $resp->assertCreated();

        return (int) $resp->json('data.id');
    }
}
