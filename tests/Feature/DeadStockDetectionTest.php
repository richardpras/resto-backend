<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DeadStockDetectionTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detects_ingredients_without_recent_movement(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, stock: 50);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 50, 5000);

        $dead = app(InventoryAnalyticsService::class)->getDeadStockIngredients((int) $outlet->id, 30);

        $this->assertNotEmpty($dead);
        $this->assertSame((string) $ingredient->id, $dead[0]['ingredientId']);
    }
}
