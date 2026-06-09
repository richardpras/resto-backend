<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Marker test — full regression executed via CI filter including analytics, costing, production.
 */
class RegressionMatrixTest extends TestCase
{
    public function test_regression_matrix_suite_is_available(): void
    {
        $this->assertTrue(class_exists(\App\Modules\Menu\Services\MenuEngineeringMatrixService::class));
        $this->assertTrue(class_exists(\App\Modules\Menu\Services\FoodCostAnalyticsService::class));
        $this->assertTrue(class_exists(\App\Modules\Menu\Services\MenuProfitabilityService::class));
    }
}
