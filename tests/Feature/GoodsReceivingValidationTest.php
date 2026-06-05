<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class GoodsReceivingValidationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_warehouse_must_belong_to_outlet(): void
    {
        $outletA = $this->createOutlet('Outlet A');
        $outletB = $this->createOutlet('Outlet B');
        $this->actingAsProcurementUser($outletA);
        $master = $this->seedProcurementMasterData((int) $outletA->id);
        $wrongWarehouseId = $this->seedWarehouse((int) $outletB->id, 'WH-B');

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $wrongWarehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ])->assertStatus(422);
    }

    public function test_over_receiving_is_blocked(): void
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

    public function test_receiving_only_allowed_for_approved_or_partially_received_po(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        DB::table('purchase_orders')->where('id', $master['poId'])->update(['status' => 'draft']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ])->assertStatus(422);
    }

    public function test_grn_progress_endpoint_returns_metrics(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $grnId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 40],
            ],
        ])->json('data.id');

        $this->getJson("/api/v1/goods-receipts/{$grnId}/progress")
            ->assertOk()
            ->assertJsonPath('data.orderedQty', 100)
            ->assertJsonPath('data.receivedQty', 0)
            ->assertJsonPath('data.remainingQty', 100)
            ->assertJsonPath('data.completionPercentage', 0);
    }
}
