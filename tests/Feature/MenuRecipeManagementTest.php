<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MenuRecipeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_menu_with_recipe_assignments(): void
    {
        $ingredientA = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Chicken',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 100,
            'min' => 10,
        ]);
        $ingredientB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Flour',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 200,
            'min' => 20,
        ]);

        $response = $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'name' => 'Crispy Chicken',
            'price' => 25000,
            'recipes' => [
                ['inventoryItemId' => $ingredientA->id, 'quantity' => 120],
                ['inventoryItemId' => $ingredientB->id, 'quantity' => 40],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Crispy Chicken');
        $response->assertJsonCount(2, 'data.recipes');

        $this->assertDatabaseHas('menu_items', [
            'tenant_id' => 1,
            'name' => 'Crispy Chicken',
        ]);
        $this->assertDatabaseHas('menu_recipes', [
            'inventory_item_id' => $ingredientA->id,
            'quantity' => 120.00,
        ]);
    }

    public function test_can_update_menu_and_replace_recipe_assignments(): void
    {
        $ingredientA = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Rice',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 1000,
            'min' => 100,
        ]);
        $ingredientB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Chicken',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 1000,
            'min' => 100,
        ]);

        $menuResponse = $this->postJson('/api/v1/menu-items', [
            'tenantId' => 1,
            'name' => 'Chicken Bowl',
            'price' => 30000,
            'recipes' => [
                ['inventoryItemId' => $ingredientA->id, 'quantity' => 150],
            ],
        ]);
        $menuResponse->assertCreated();
        $menuId = $menuResponse->json('data.id');

        $updateResponse = $this->patchJson("/api/v1/menu-items/{$menuId}", [
            'name' => 'Chicken Rice Bowl',
            'price' => 32000,
            'recipes' => [
                ['inventoryItemId' => $ingredientA->id, 'quantity' => 130],
                ['inventoryItemId' => $ingredientB->id, 'quantity' => 80],
            ],
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Chicken Rice Bowl');
        $updateResponse->assertJsonCount(2, 'data.recipes');
        $this->assertDatabaseHas('menu_recipes', [
            'menu_item_id' => $menuId,
            'inventory_item_id' => $ingredientB->id,
            'quantity' => 80.00,
        ]);
        $this->assertSame(2, DB::table('menu_recipes')->where('menu_item_id', $menuId)->count());
    }
}
