<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuProfitabilityService;
use Tests\TestCase;

class MarginPercentTest extends TestCase
{
    public function test_margin_percent_formula(): void
    {
        $service = app(MenuProfitabilityService::class);

        $this->assertSame(50.0, $service->calculateMarginPercent(100000, 50000));
        $this->assertSame(0.0, $service->calculateMarginPercent(0, 50000));
        $this->assertSame(-25.0, $service->calculateMarginPercent(80000, 100000));
    }
}
