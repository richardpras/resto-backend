<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SupplierPerformanceAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_supplier_analytics_returns_performance_metrics(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);
        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 10],
        ]);

        $response = $this->getJson('/api/v1/procurement/analytics/suppliers?outletId='.(int) $outlet->id)
            ->assertOk();

        $this->assertNotEmpty($response->json('data'));
        $this->assertArrayHasKey('supplierName', $response->json('data.0'));
        $this->assertArrayHasKey('purchaseAmount', $response->json('data.0'));
        $this->assertArrayHasKey('matchRate', $response->json('data.0'));
    }
}
