<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_automation_regression_suite_is_available(): void
    {
        $this->assertTrue(class_exists(\App\Modules\Menu\Services\MenuAutomationService::class));
        $this->assertTrue(class_exists(\App\Modules\Menu\Services\AlertEvaluationService::class));
    }
}
