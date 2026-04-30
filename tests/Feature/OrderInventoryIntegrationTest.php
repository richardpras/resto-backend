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
            'unit' => 'gram',
            'current_stock' => 100,
            'minimum_stock' => 10,
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
            'ingredient_id' => $ingredient->id,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'tenant_id' => 1,
            'outlet_id' => 1,
            'order_type' => 'dine_in',
            'items' => [
                [
                    'menu_id' => $menuId,
                    'menu_name' => 'Fried Chicken',
                    'qty' => 3,
                    'price' => 10,
                ],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 15, 'split_bill_label' => 'Guest A'],
                ['method' => 'qris', 'amount' => 15, 'split_bill_label' => 'Guest B'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => 94.00,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'movement_type' => 'out',
            'quantity' => 6.00,
            'source' => 'order_payment',
        ]);
    }

    public function test_partial_payment_does_not_deduct_stock(): void
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Rice',
            'unit' => 'gram',
            'current_stock' => 100,
            'minimum_stock' => 10,
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
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'tenant_id' => 1,
            'outlet_id' => 1,
            'order_type' => 'dine_in',
            'items' => [
                [
                    'menu_id' => $menuId,
                    'menu_name' => 'Rice Bowl',
                    'qty' => 2,
                    'price' => 20,
                ],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 10, 'split_bill_label' => 'Deposit'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'current_stock' => 100.00,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'source' => 'order_payment',
        ]);
    }
}
