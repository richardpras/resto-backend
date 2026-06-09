<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementTrendAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_trends_returns_twelve_month_series(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $response = $this->getJson('/api/v1/procurement/analytics/trends?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'months',
                    'purchaseOrders',
                    'receipts',
                    'invoices',
                    'payments',
                    'spend',
                ],
            ]);

        $this->assertCount(12, $response->json('data.months'));
        $this->assertCount(12, $response->json('data.spend'));
    }
}
