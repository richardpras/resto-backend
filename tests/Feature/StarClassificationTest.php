<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use Tests\TestCase;

class StarClassificationTest extends TestCase
{
    public function test_star_when_high_popularity_and_high_margin(): void
    {
        $service = app(MenuEngineeringMatrixService::class);
        $this->assertSame(
            MenuEngineeringMatrixService::STAR,
            $service->classify(40.0, 50000.0, 25.0, 30000.0),
        );
    }
}
