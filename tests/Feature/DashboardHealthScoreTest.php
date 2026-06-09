<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Modules\Menu\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class DashboardHealthScoreTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_health_score_applies_critical_alert_penalty(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        AutomationAlert::query()->create([
            'outlet_id' => $outlet->id,
            'alert_type' => 'star_to_dog',
            'severity' => 'critical',
            'title' => 'Critical',
            'description' => 'Test',
            'status' => 'open',
            'triggered_at' => now(),
        ]);

        $health = app(DashboardService::class)->getHealth((int) $outlet->id);

        $this->assertLessThan(100.0, $health['score']);
        $this->assertContains($health['band'], ['excellent', 'good', 'warning', 'critical']);
    }
}
