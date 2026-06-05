<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseOrderReceivingProgressTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_partial_receive_updates_status_and_progress(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 40],
        ]);

        $this->assertDatabaseHas('purchase_orders', ['id' => $master['poId'], 'status' => 'partially_received']);

        $progress = $this->getJson("/api/v1/purchase-orders/{$master['poId']}/progress")->assertOk();
        $progress->assertJsonPath('data.totalOrderedQty', 100);
        $progress->assertJsonPath('data.totalReceivedQty', 40);
        $progress->assertJsonPath('data.totalRemainingQty', 60);
        $progress->assertJsonPath('data.completionPercentage', 40);
    }

    public function test_over_receive_is_blocked(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 150],
            ],
        ])->assertStatus(422);
    }
}
