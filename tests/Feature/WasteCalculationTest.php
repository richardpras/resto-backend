<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WasteCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_waste_adjusted_cost_formula(): void
    {
        $service = app(RecipeCostService::class);

        $this->assertSame(131250.0, $service->calculateWasteAdjustedCost(125000, 5));
        $this->assertSame(125000.0, $service->calculateWasteAdjustedCost(125000, 0));
    }
}
