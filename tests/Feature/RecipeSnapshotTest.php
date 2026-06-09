<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemRecipeSnapshot;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class RecipeSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_payment_creates_immutable_recipe_snapshot(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 100, 'created_at' => now(), 'updated_at' => now()],
        );
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 100, 10000);

        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.25);
        app(RecipeVersionService::class)->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'RECIPE-SNAP-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 50000,
            'total' => 50000,
            'subtotal' => 50000,
            'tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => (string) $menu['menuId'],
            'name' => 'Menu',
            'qty' => 2,
            'price' => 25000,
            'line_total' => 50000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->with('items')->findOrFail($orderId);
        app(RecipeStockDeductionService::class)->deductForPaidOrder($order);

        $snapshot = OrderItemRecipeSnapshot::query()->where('order_item_id', $orderItemId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(1, (int) $snapshot->version_number);
        $this->assertSame(0.25, (float) $snapshot->recipe_snapshot_json['items'][0]['quantity']);
    }
}
