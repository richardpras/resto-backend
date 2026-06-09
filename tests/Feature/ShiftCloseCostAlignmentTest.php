<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class ShiftCloseCostAlignmentTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_shift_close_cogs_equals_sum_of_sale_movements(): void
    {
        $outlet = $this->createValuationOutlet('Shift COGS Align');
        $this->actingAsInventoryUser($outlet);
        AccountingSetting::query()->updateOrCreate(
            ['tenant_id' => null, 'outlet_id' => $outlet->id],
            ['revenue_posting_mode' => AccountingSetting::MODE_SHIFT_CLOSE],
        );
        DB::table('accounts')->insert([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, price: 5000);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 100, 'created_at' => now(), 'updated_at' => now()],
        );
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 100, 10500);

        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2);
        $orderCode = 'SHIFT-COGS-'.uniqid();

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => $orderCode,
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 50000,
            'total' => 50000,
            'subtotal' => 50000,
            'tax' => 0,
            'is_posted' => false,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'item_id' => (string) $menu['menuId'],
            'name' => 'Test Menu',
            'qty' => 2,
            'price' => 25000,
            'line_total' => 50000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(RecipeStockDeductionService::class)->deductForPaidOrder($order->fresh(['items']));

        $movementCogs = (float) DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->where('source_id', $orderCode)
            ->sum('total_cost');

        $result = app(OrderService::class)->closeShiftAndPostJournal(1, (int) $outlet->id);
        $this->assertSame(round($movementCogs, 2), round((float) $result['totalCogs'], 2));
        $this->assertSame(42000.0, round($movementCogs, 2));
    }
}
