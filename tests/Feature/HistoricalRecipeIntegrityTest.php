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

class HistoricalRecipeIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_historical_order_keeps_original_recipe_version_after_change(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
            ['stock' => 100, 'created_at' => now(), 'updated_at' => now()],
        );
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 100, 10000);

        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.2);
        $service = app(RecipeVersionService::class);
        $v1 = $service->getActiveVersion($menu['menuId']);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'HIST-RECIPE-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 30000,
            'total' => 30000,
            'subtotal' => 30000,
            'tax' => 0,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => (string) $menu['menuId'],
            'name' => 'Menu',
            'qty' => 1,
            'price' => 30000,
            'line_total' => 30000,
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $order = \App\Models\Modules\Orders\Domain\Order::query()->with('items')->findOrFail($orderId);
        app(RecipeStockDeductionService::class)->deductForPaidOrder($order);

        $service->createVersion($menu['menuId'], [
            ['ingredientId' => (int) $ingredient->id, 'quantity' => 0.3],
        ], activate: true);

        $snapshot = OrderItemRecipeSnapshot::query()->where('order_item_id', $orderItemId)->firstOrFail();
        $this->assertSame((int) $v1->id, (int) $snapshot->recipe_version_id);
        $this->assertSame(1, (int) $snapshot->version_number);
        $this->assertSame(0.2, (float) $snapshot->recipe_snapshot_json['items'][0]['quantity']);
        $this->assertSame(2, (int) $service->getActiveVersion($menu['menuId'])->version_number);
    }
}
