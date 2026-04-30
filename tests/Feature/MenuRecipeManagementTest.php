<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'unit' => 'gram',
            'current_stock' => 100,
            'minimum_stock' => 10,
        ]);
        $ingredientB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Flour',
            'unit' => 'gram',
            'current_stock' => 200,
            'minimum_stock' => 20,
        ]);

        $response = $this->postJson('/api/v1/menu-items', [
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Crispy Chicken',
            'price' => 25000,
            'is_active' => true,
            'recipes' => [
                ['ingredient_id' => $ingredientA->id, 'quantity' => 120],
                ['ingredient_id' => $ingredientB->id, 'quantity' => 40],
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
            'ingredient_id' => $ingredientA->id,
            'quantity' => 120.00,
        ]);
    }

    public function test_can_update_menu_and_replace_recipe_assignments(): void
    {
        $ingredientA = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Rice',
            'unit' => 'gram',
            'current_stock' => 1000,
            'minimum_stock' => 100,
        ]);
        $ingredientB = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Chicken',
            'unit' => 'gram',
            'current_stock' => 1000,
            'minimum_stock' => 100,
        ]);

        $menuResponse = $this->postJson('/api/v1/menu-items', [
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Chicken Bowl',
            'price' => 30000,
            'recipes' => [
                ['ingredient_id' => $ingredientA->id, 'quantity' => 150],
            ],
        ]);
        $menuId = $menuResponse->json('data.id');

        $updateResponse = $this->putJson("/api/v1/menu-items/{$menuId}", [
            'name' => 'Chicken Rice Bowl',
            'price' => 32000,
            'recipes' => [
                ['ingredient_id' => $ingredientA->id, 'quantity' => 130],
                ['ingredient_id' => $ingredientB->id, 'quantity' => 80],
            ],
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Chicken Rice Bowl');
        $updateResponse->assertJsonCount(2, 'data.recipes');
        $this->assertDatabaseHas('menu_recipes', [
            'menu_item_id' => $menuId,
            'ingredient_id' => $ingredientB->id,
            'quantity' => 80.00,
        ]);
    }
}
