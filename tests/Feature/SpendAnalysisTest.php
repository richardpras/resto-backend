<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SpendAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_spend_analysis_returns_breakdown_series(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);
        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 5],
        ]);

        $this->getJson('/api/v1/procurement/analytics/spend?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'monthlySpend',
                    'supplierSpend',
                    'categorySpend',
                    'warehouseSpend',
                ],
            ]);
    }
}
