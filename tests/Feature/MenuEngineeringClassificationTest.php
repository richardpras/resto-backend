<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class MenuEngineeringClassificationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_matrix_classifies_items_using_benchmarks(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $starMenu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 0.1, menuPrice: 100000);
        $dogMenu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id, recipeQty: 2, menuPrice: 20000);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 100, 10000);

        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'ME-1', 'source' => 'pos',
            'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
            'paid_total' => 100000, 'total' => 100000, 'subtotal' => 100000, 'tax' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $orderId, 'item_id' => (string) $starMenu['menuId'], 'name' => 'Star', 'qty' => 80, 'price' => 1000, 'line_total' => 80000, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $orderId, 'item_id' => (string) $dogMenu['menuId'], 'name' => 'Dog', 'qty' => 20, 'price' => 1000, 'line_total' => 20000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $matrix = app(MenuEngineeringMatrixService::class)->generateMatrix((int) $outlet->id);
        $byId = collect($matrix['items'])->keyBy('menuItemId');

        $this->assertContains($byId[(string) $starMenu['menuId']]['classification'], [
            MenuEngineeringMatrixService::STAR,
            MenuEngineeringMatrixService::PLOWHORSE,
        ]);
        $this->assertContains($byId[(string) $dogMenu['menuId']]['classification'], [
            MenuEngineeringMatrixService::DOG,
            MenuEngineeringMatrixService::PUZZLE,
        ]);
    }
}
