<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class MatchConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_can_create_and_update_match_config(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $create = $this->postJson('/api/v1/procurement/match-configs', [
            'outletId' => (int) $outlet->id,
            'quantityTolerancePercent' => 0,
            'priceTolerancePercent' => 3,
            'amountTolerancePercent' => 3,
            'autoApproveWithinTolerance' => true,
            'isActive' => true,
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->patchJson("/api/v1/procurement/match-configs/{$id}", [
            'priceTolerancePercent' => 5,
            'amountTolerancePercent' => 5,
        ])->assertOk()->assertJsonPath('data.priceTolerancePercent', 5);
    }
}

