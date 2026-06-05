<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseOrderApprovalTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_cannot_approve_draft_purchase_order(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Vendor',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Item',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'outletId' => $outlet->id,
            'date' => now()->toDateString(),
            'supplierId' => $supplierId,
            'items' => [
                ['inventoryItemId' => $ingredientId, 'qty' => 2, 'unit' => 'kg', 'price' => 1000],
            ],
        ])->json('data.id');

        $this->patchJson("/api/v1/purchase-orders/{$poId}/approve")->assertStatus(422);
    }

    public function test_cannot_cancel_approved_purchase_order_with_grn(): void
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
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
            ],
        ])->assertCreated();

        $this->patchJson("/api/v1/purchase-orders/{$master['poId']}/cancel")->assertStatus(422);
    }
}
