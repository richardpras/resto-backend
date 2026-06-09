<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\RecipeCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YieldCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_yield_adjusted_cost_formula(): void
    {
        $service = app(RecipeCostService::class);

        $this->assertSame(125000.0, $service->calculateYieldAdjustedCost(100000, 80));
        $this->assertSame(100000.0, $service->calculateYieldAdjustedCost(100000, 100));
    }
}
