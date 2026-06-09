<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use Tests\TestCase;

class PuzzleClassificationTest extends TestCase
{
    public function test_puzzle_when_low_popularity_and_high_margin(): void
    {
        $service = app(MenuEngineeringMatrixService::class);
        $this->assertSame(
            MenuEngineeringMatrixService::PUZZLE,
            $service->classify(10.0, 50000.0, 25.0, 30000.0),
        );
    }
}
