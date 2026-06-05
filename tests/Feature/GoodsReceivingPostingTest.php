<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class GoodsReceivingPostingTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_inventory_posted_only_after_post_action(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $initialStock = (float) DB::table('ingredients')->where('id', $master['ingredientId'])->value('stock');

        $grnId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 15, 'unitCost' => 9500],
            ],
        ])->json('data.id');

        $this->assertSame($initialStock, (float) DB::table('ingredients')->where('id', $master['ingredientId'])->value('stock'));

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();
        $this->assertSame($initialStock, (float) DB::table('ingredients')->where('id', $master['ingredientId'])->value('stock'));

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/post")->assertOk();

        $this->assertDatabaseHas('goods_receiving_note_items', [
            'ingredient_id' => $master['ingredientId'],
            'received_qty' => 15,
            'original_po_cost' => 10000,
            'actual_received_cost' => 9500,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $master['ingredientId'],
            'outlet_id' => $outlet->id,
            'type' => 'purchase',
            'unit_cost' => 9500,
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $master['poItemId'],
            'received_qty' => 15,
        ]);
    }

    public function test_po_progress_updates_after_post(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 40],
        ]);

        $progress = $this->getJson("/api/v1/purchase-orders/{$master['poId']}/progress")->assertOk();
        $progress->assertJsonPath('data.totalReceivedQty', 40);
        $progress->assertJsonPath('data.totalRemainingQty', 60);
        $progress->assertJsonPath('data.completionPercentage', 40);
    }
}
