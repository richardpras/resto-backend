<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class GoodsReceivingSummaryTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_procurement_summary_includes_receiving_counters(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $draftId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ])->json('data.id');

        $receivedId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
            ],
        ])->json('data.id');
        $this->patchJson("/api/v1/goods-receipts/{$receivedId}/receive")->assertOk();

        $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 3],
        ], $warehouseId);

        $this->patchJson("/api/v1/goods-receipts/{$draftId}/cancel")->assertOk();

        $response = $this->getJson('/api/v1/procurement/summary?outletId='.$outlet->id)->assertOk();

        $response->assertJsonPath('data.draftReceivings', 0);
        $response->assertJsonPath('data.receivedReceivings', 1);
        $response->assertJsonPath('data.postedReceivings', 1);
        $response->assertJsonPath('data.cancelledReceivings', 1);
        $response->assertJsonPath('data.todayReceivings', 1);
        $response->assertJsonPath('data.todayReceivedValue', 30000);
    }
}
