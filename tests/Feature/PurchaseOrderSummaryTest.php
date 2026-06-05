<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseOrderSummaryTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_procurement_summary_includes_po_dashboard_counters(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $this->seedProcurementMasterData((int) $outlet->id);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Extra Vendor',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Spice',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draftId = $this->postJson('/api/v1/purchase-orders', [
            'outletId' => $outlet->id,
            'date' => now()->toDateString(),
            'supplierId' => $supplierId,
            'items' => [['inventoryItemId' => $ingredientId, 'qty' => 1, 'unit' => 'kg', 'price' => 1000]],
        ])->json('data.id');

        $submittedId = $this->postJson('/api/v1/purchase-orders', [
            'outletId' => $outlet->id,
            'date' => now()->toDateString(),
            'supplierId' => $supplierId,
            'items' => [['inventoryItemId' => $ingredientId, 'qty' => 2, 'unit' => 'kg', 'price' => 1000]],
        ])->json('data.id');
        $this->patchJson("/api/v1/purchase-orders/{$submittedId}/submit")->assertOk();

        $response = $this->getJson('/api/v1/procurement/summary?outletId='.$outlet->id);
        $response->assertOk();
        $response->assertJsonPath('data.draftPOs', 1);
        $response->assertJsonPath('data.submittedPOs', 1);
        $response->assertJsonPath('data.approvedPOs', 1);
        $response->assertJsonPath('data.partiallyReceivedPOs', 0);
        $response->assertJsonPath('data.receivedPOs', 0);
        $response->assertJsonPath('data.cancelledPOs', 0);

        $this->assertNotNull($draftId);
    }
}
