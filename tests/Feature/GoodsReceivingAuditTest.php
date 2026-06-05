<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class GoodsReceivingAuditTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_lifecycle_actions_write_audit_logs(): void
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
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 8],
            ],
        ])->json('data.id');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'goods_receipt_created',
            'entity_type' => 'goods_receiving_note',
            'entity_id' => $grnId,
        ]);

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'goods_receipt_received',
            'entity_id' => $grnId,
        ]);

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/post")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'goods_receipt_posted',
            'entity_id' => $grnId,
        ]);

        $cancelId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 2],
            ],
        ])->json('data.id');

        $this->patchJson("/api/v1/goods-receipts/{$cancelId}/cancel")->assertOk();
        $this->assertTrue(
            PosEventLog::query()->where('event_type', 'goods_receipt_cancelled')->where('entity_id', $cancelId)->exists()
        );
    }
}
