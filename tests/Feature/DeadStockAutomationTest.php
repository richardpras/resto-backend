<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\Services\AlertEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DeadStockAutomationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_triggers_dead_stock_automation_alert(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id, price: 0, stock: 0);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 20, 5000);

        app(AlertEvaluationService::class)->evaluateDeadStock((int) $outlet->id);

        $this->assertTrue(
            AutomationAlert::query()
                ->where('outlet_id', $outlet->id)
                ->where('alert_type', 'dead_stock')
                ->exists()
        );
    }
}
