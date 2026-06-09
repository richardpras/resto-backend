<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\AlertEvaluationService;
use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class YieldAlertAutomationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_triggers_yield_loss_automation_alert(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $ingredient->id);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        app(RecipeCostService::class)->updateWastePercent($menu['menuId'], 12.0);

        app(AlertEvaluationService::class)->evaluateYieldLoss((int) $outlet->id);

        $this->assertTrue(
            AutomationAlert::query()
                ->where('outlet_id', $outlet->id)
                ->where('alert_type', 'yield_loss')
                ->exists()
        );
    }
}
