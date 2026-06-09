<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use Tests\TestCase;

class PlowhorseClassificationTest extends TestCase
{
    public function test_plowhorse_when_high_popularity_and_low_margin(): void
    {
        $service = app(MenuEngineeringMatrixService::class);
        $this->assertSame(
            MenuEngineeringMatrixService::PLOWHORSE,
            $service->classify(40.0, 10000.0, 25.0, 30000.0),
        );
    }
}
