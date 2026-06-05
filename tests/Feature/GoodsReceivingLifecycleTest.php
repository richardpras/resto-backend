<?php

namespace Tests\Feature;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class GoodsReceivingLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_draft_receive_post_lifecycle(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $create = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 25],
            ],
        ])->assertCreated();

        $grnId = (int) $create->json('data.id');
        $create->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseMissing('stock_movements', [
            'inventory_item_id' => $master['ingredientId'],
            'type' => 'purchase',
        ]);

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseMissing('stock_movements', [
            'inventory_item_id' => $master['ingredientId'],
            'type' => 'purchase',
        ]);

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $master['ingredientId'],
            'outlet_id' => $outlet->id,
            'type' => 'purchase',
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $master['poId'],
            'status' => 'partially_received',
        ]);
    }

    public function test_cancel_draft_goods_receipt(): void
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
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ])->json('data.id');

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancel_received_goods_receipt(): void
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
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ])->json('data.id');

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cannot_cancel_posted_goods_receipt(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/cancel")->assertStatus(422);
    }

    public function test_draft_goods_receipt_can_be_deleted(): void
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
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
            ],
        ])->json('data.id');

        $this->deleteJson("/api/v1/goods-receipts/{$grnId}")->assertOk();
        $this->assertDatabaseMissing('goods_receiving_notes', ['id' => $grnId]);
    }

    public function test_received_goods_receipt_cannot_be_edited(): void
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
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
            ],
        ])->json('data.id');

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();
        $this->patchJson("/api/v1/goods-receipts/{$grnId}", ['notes' => 'changed'])->assertStatus(422);
    }
}
