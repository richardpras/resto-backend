<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuProfitabilityClassificationService;
use Tests\TestCase;

class ProfitabilityClassificationTest extends TestCase
{
    public function test_profitability_classification_bands(): void
    {
        $service = app(MenuProfitabilityClassificationService::class);

        $this->assertSame(MenuProfitabilityClassificationService::LOSS, $service->classify(-5));
        $this->assertSame(MenuProfitabilityClassificationService::LOW, $service->classify(15));
        $this->assertSame(MenuProfitabilityClassificationService::MEDIUM, $service->classify(30));
        $this->assertSame(MenuProfitabilityClassificationService::HIGH, $service->classify(50));
        $this->assertSame(MenuProfitabilityClassificationService::PREMIUM, $service->classify(65));
    }

    public function test_classification_boundary_values(): void
    {
        $service = app(MenuProfitabilityClassificationService::class);

        $this->assertSame(MenuProfitabilityClassificationService::LOW, $service->classify(0));
        $this->assertSame(MenuProfitabilityClassificationService::MEDIUM, $service->classify(20));
        $this->assertSame(MenuProfitabilityClassificationService::HIGH, $service->classify(40));
        $this->assertSame(MenuProfitabilityClassificationService::PREMIUM, $service->classify(60));
    }
}
