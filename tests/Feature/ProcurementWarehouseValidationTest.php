<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementWarehouseValidationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_po_stores_destination_warehouse_id(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'outletId' => $outlet->id,
            'date' => now()->toDateString(),
            'supplierId' => $master['supplierId'],
            'destinationWarehouseId' => $warehouseId,
            'status' => 'draft',
            'items' => [
                [
                    'inventoryItemId' => $master['ingredientId'],
                    'qty' => 5,
                    'unit' => 'kg',
                    'price' => 10000,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.destinationWarehouseId', (string) $warehouseId);

        $this->assertDatabaseHas('purchase_orders', [
            'destination_warehouse_id' => $warehouseId,
        ]);
    }

    public function test_grn_rejects_warehouse_from_different_outlet(): void
    {
        $outletA = $this->createOutlet('Outlet A');
        $outletB = $this->createOutlet('Outlet B');
        $this->actingAsProcurementUser($outletA);
        $master = $this->seedProcurementMasterData((int) $outletA->id);
        $wrongWarehouseId = $this->seedWarehouse((int) $outletB->id, 'WH-B');

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'destinationWarehouseId' => $wrongWarehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_grn_stores_po_destination_warehouse_when_not_overridden(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        DB::table('purchase_orders')->where('id', $master['poId'])->update([
            'destination_warehouse_id' => $warehouseId,
        ]);

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.warehouseId', (string) $warehouseId);
        $response->assertJsonPath('data.destinationWarehouseId', (string) $warehouseId);

        $this->assertDatabaseHas('goods_receiving_notes', [
            'purchase_order_id' => $master['poId'],
            'destination_warehouse_id' => $warehouseId,
            'warehouse_id' => $warehouseId,
            'status' => 'draft',
        ]);
    }

    private function seedWarehouse(int $outletId, string $code = 'WH-01'): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'outlet_id' => $outletId,
            'code' => $code.'-'.uniqid(),
            'name' => 'Warehouse '.$code,
            'type' => 'outlet',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
