<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class SplitBillReceiptPrintTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_split_fully_paid_queues_one_receipt_per_split_and_skips_full_order_receipt(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Split Receipt Outlet');
        $this->seedPosPostingAccountsAndMappings((int) $outlet->id);
        [, $menuItem] = $this->seedRecipeContext((int) $outlet->id);

        $orderId = $this->createConfirmedOrder(
            (int) $outlet->id,
            (int) $user->id,
            'SPLIT-RCP-1',
            (int) $menuItem->id,
            qty: 3,
            unitPrice: 40,
        );
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $splitA = $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Guest A',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 2,
                'amount' => 80,
            ]],
        ]);
        $splitA->assertCreated();
        $splitAId = (int) $splitA->json('data.id');

        $splitB = $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Guest B',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 1,
                'amount' => 40,
            ]],
        ]);
        $splitB->assertCreated();
        $splitBId = (int) $splitB->json('data.id');

        Queue::fake();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'cash',
                'amount' => 80,
                'orderSplitId' => $splitAId,
                'allocations' => [[
                    'orderItemId' => $orderItemId,
                    'qty' => 2,
                    'amount' => 80,
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(
            1,
            ReceiptRenderHistory::query()
                ->where('source_type', 'order')
                ->where('source_id', $orderId)
                ->where('order_split_id', $splitAId)
                ->count(),
            'Fully paid split A must render one split receipt.'
        );
        $this->assertSame(
            0,
            ReceiptRenderHistory::query()
                ->where('source_type', 'order')
                ->where('source_id', $orderId)
                ->whereNull('order_split_id')
                ->count(),
            'Partial split payment must not render a full-order receipt.'
        );

        $splitAHistory = ReceiptRenderHistory::query()
            ->where('source_id', $orderId)
            ->where('order_split_id', $splitAId)
            ->firstOrFail();
        $thermalA = (string) $splitAHistory->thermal_text;
        $this->assertStringContainsString('Guest A', $thermalA);
        $this->assertStringContainsString('Split', $thermalA);
        $this->assertStringContainsString('Cash', $thermalA);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'transfer',
                'amount' => 40,
                'orderSplitId' => $splitBId,
                'allocations' => [[
                    'orderItemId' => $orderItemId,
                    'qty' => 1,
                    'amount' => 40,
                ]],
            ]],
        ])->assertOk();

        $this->assertSame('paid', (string) DB::table('orders')->where('id', $orderId)->value('payment_status'));

        $this->assertSame(
            1,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->where('order_split_id', $splitBId)
                ->count(),
            'Fully paid split B must render one split receipt.'
        );
        $this->assertSame(
            0,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->whereNull('order_split_id')
                ->count(),
            'Fully paid split order must not auto-print a full-order receipt.'
        );
        $this->assertSame(
            2,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->whereNotNull('order_split_id')
                ->count(),
            'Split order must produce exactly one receipt per paid split.'
        );
    }

    public function test_partial_split_payment_does_not_print_receipt_until_fully_paid(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Split Partial Receipt');
        $this->seedPosPostingAccountsAndMappings((int) $outlet->id);
        [, $menuItem] = $this->seedRecipeContext((int) $outlet->id);

        $orderId = $this->createConfirmedOrder(
            (int) $outlet->id,
            (int) $user->id,
            'SPLIT-RCP-PARTIAL',
            (int) $menuItem->id,
            qty: 2,
            unitPrice: 50,
        );
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $split = $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Guest A',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 2,
                'amount' => 100,
            ]],
        ]);
        $split->assertCreated();
        $splitId = (int) $split->json('data.id');

        Queue::fake();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'cash',
                'amount' => 40,
                'orderSplitId' => $splitId,
                'allocations' => [[
                    'orderItemId' => $orderItemId,
                    'qty' => 1,
                    'amount' => 40,
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(
            0,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->where('order_split_id', $splitId)
                ->count(),
            'Partial split payment must not print split receipt yet.'
        );

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'cash',
                'amount' => 60,
                'orderSplitId' => $splitId,
                'allocations' => [[
                    'orderItemId' => $orderItemId,
                    'qty' => 1,
                    'amount' => 60,
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(
            1,
            ReceiptRenderHistory::query()
                ->where('source_id', $orderId)
                ->where('order_split_id', $splitId)
                ->count(),
            'Split receipt prints once when cumulative payments cover split due.'
        );
    }

    /** @return array{0: User, 1: Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'split-rcp-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    /** @return array{0: Ingredient, 1: MenuItem} */
    private function seedRecipeContext(int $outletId): array
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Split RCP Ingredient '.uniqid(),
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
            'name' => 'Split RCP Menu '.uniqid(),
            'category' => 'main',
            'price' => 50,
            'available' => true,
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => (int) $menuItem->id,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$ingredient, $menuItem];
    }

    private function createConfirmedOrder(int $outletId, int $userId, string $code, int $menuItemId, int $qty, float $unitPrice): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'SPLIT-RCP-T-'.uniqid(),
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
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => (string) $menuItemId, 'name' => 'Split Menu', 'qty' => $qty, 'price' => $unitPrice],
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
