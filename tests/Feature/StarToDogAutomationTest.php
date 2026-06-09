<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Modules\Menu\Services\AlertEvaluationService;
use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class StarToDogAutomationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_triggers_critical_alert_when_star_becomes_dog(): void
    {
        $outlet = $this->createValuationOutlet();
        $menu = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        $lastWeek = now()->subWeek()->toDateString();
        $today = now()->toDateString();

        MenuEngineeringSnapshot::query()->create([
            'snapshot_date' => $lastWeek,
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'quantity_sold' => 10,
            'popularity_percent' => 50,
            'contribution_margin' => 50000,
            'margin_percent' => 60,
            'classification' => MenuEngineeringMatrixService::STAR,
        ]);
        MenuEngineeringSnapshot::query()->create([
            'snapshot_date' => $today,
            'outlet_id' => $outlet->id,
            'menu_item_id' => $menu['menuId'],
            'quantity_sold' => 1,
            'popularity_percent' => 5,
            'contribution_margin' => 5000,
            'margin_percent' => 10,
            'classification' => MenuEngineeringMatrixService::DOG,
        ]);

        app(AlertEvaluationService::class)->evaluateClassificationMovements((int) $outlet->id);

        $alert = AutomationAlert::query()
            ->where('outlet_id', $outlet->id)
            ->where('alert_type', 'star_to_dog')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
    }
}
