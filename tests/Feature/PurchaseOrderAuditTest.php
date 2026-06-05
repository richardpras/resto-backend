<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseOrderAuditTest extends TestCase
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

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Audit Vendor',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Audit Item',
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
            'items' => [['inventoryItemId' => $ingredientId, 'qty' => 5, 'unit' => 'kg', 'price' => 2000]],
        ])->json('data.id');

        $this->patchJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-orders/{$poId}/approve")->assertOk();

        $this->createPostedGoodsReceipt((int) $poId, (int) $outlet->id, [
            ['inventoryItemId' => $ingredientId, 'receivedQty' => 2],
        ]);

        $this->assertTrue(PosEventLog::query()->where('event_type', 'purchase_order_submitted')->where('entity_id', $poId)->exists());
        $this->assertTrue(PosEventLog::query()->where('event_type', 'purchase_order_approved')->where('entity_id', $poId)->exists());
        $this->assertTrue(PosEventLog::query()->where('event_type', 'purchase_order_partially_received')->where('entity_id', $poId)->exists());
    }
}
