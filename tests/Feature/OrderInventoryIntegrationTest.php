<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_with_multi_payment_deducts_stock_based_on_recipe(): void
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Chicken',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 100,
            'min' => 10,
            'price' => 0,
        ]);

        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => 1,
            'stock' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Fried Chicken',
            'price' => 10,
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

        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'ORD-MULTIPAY-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $menuId, 'name' => 'Fried Chicken', 'qty' => 3, 'price' => 10],
            ],
            'subtotal' => 30,
            'tax' => 0,
            'total' => 30,
            'payments' => [],
        ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 15],
                ['method' => 'qris', 'amount' => 15],
            ],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $required = 3 * 2.0;
        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => $ingredient->id,
            'outlet_id' => 1,
            'stock' => 100 - $required,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $ingredient->id,
            'outlet_id' => 1,
            'type' => 'sale',
            'quantity' => $required,
            'source_type' => 'order_payment',
        ]);
    }

    public function test_partial_payment_does_not_deduct_stock(): void
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Rice',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 100,
            'min' => 10,
        ]);

        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => 1,
            'stock' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Rice Bowl',
            'price' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuId,
            'inventory_item_id' => $ingredient->id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'ORD-PARTIAL-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $menuId, 'name' => 'Rice Bowl', 'qty' => 2, 'price' => 20],
            ],
            'subtotal' => 40,
            'tax' => 0,
            'total' => 40,
            'payments' => [],
        ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 10],
            ],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'partial');

        $this->assertDatabaseHas('inventory_stocks', [
            'ingredient_id' => $ingredient->id,
            'outlet_id' => 1,
            'stock' => 100.00,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'inventory_item_id' => $ingredient->id,
            'source_type' => 'order_payment',
        ]);
    }
}
