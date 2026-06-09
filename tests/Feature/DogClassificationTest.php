<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\MenuEngineeringMatrixService;
use Tests\TestCase;

class DogClassificationTest extends TestCase
{
    public function test_dog_when_low_popularity_and_low_margin(): void
    {
        $service = app(MenuEngineeringMatrixService::class);
        $this->assertSame(
            MenuEngineeringMatrixService::DOG,
            $service->classify(10.0, 10000.0, 25.0, 30000.0),
        );
    }
}
