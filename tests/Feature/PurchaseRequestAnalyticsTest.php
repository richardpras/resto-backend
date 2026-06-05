<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseRequestAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_procurement_summary_includes_purchase_request_counters(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Beans',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Vendor Analytics',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draftId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [['inventoryItemId' => $ingredientId, 'quantity' => 1, 'unit' => 'kg']],
        ])->json('data.id');

        $submittedId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [['inventoryItemId' => $ingredientId, 'quantity' => 2, 'unit' => 'kg']],
        ])->json('data.id');
        $this->postJson("/api/v1/purchase-requests/{$submittedId}/submit")->assertOk();

        $approvedId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [['inventoryItemId' => $ingredientId, 'quantity' => 3, 'unit' => 'kg']],
        ])->json('data.id');
        $this->postJson("/api/v1/purchase-requests/{$approvedId}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$approvedId}/approve")->assertOk();

        $convertedId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [['inventoryItemId' => $ingredientId, 'quantity' => 4, 'unit' => 'kg']],
        ])->json('data.id');
        $this->postJson("/api/v1/purchase-requests/{$convertedId}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$convertedId}/approve")->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$convertedId}/convert", ['supplierId' => $supplierId])->assertOk();

        $response = $this->getJson('/api/v1/procurement/summary?outletId='.$outlet->id);

        $response->assertOk();
        $response->assertJsonPath('data.purchaseRequests', 4);
        $response->assertJsonPath('data.submittedRequests', 1);
        $response->assertJsonPath('data.approvedRequests', 1);
        $response->assertJsonPath('data.convertedRequests', 1);

        $this->assertNotNull($draftId);
    }
}
