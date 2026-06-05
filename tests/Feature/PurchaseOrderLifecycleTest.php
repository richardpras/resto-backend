<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    /** @return array{outletId:int,supplierId:int,ingredientId:int,poId:int} */
    private function seedDraftPo(): array
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'PO Lifecycle Vendor',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Pasta',
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
                ['inventoryItemId' => $ingredientId, 'qty' => 10, 'unit' => 'kg', 'price' => 10000],
            ],
        ])->json('data.id');

        return [
            'outletId' => (int) $outlet->id,
            'supplierId' => $supplierId,
            'ingredientId' => $ingredientId,
            'poId' => (int) $poId,
        ];
    }

    public function test_submit_approve_and_close_purchase_order(): void
    {
        $ctx = $this->seedDraftPo();

        $this->patchJson("/api/v1/purchase-orders/{$ctx['poId']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->patchJson("/api/v1/purchase-orders/{$ctx['poId']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->createPostedGoodsReceipt($ctx['poId'], $ctx['outletId'], [
            ['inventoryItemId' => $ctx['ingredientId'], 'receivedQty' => 10],
        ]);

        $this->assertDatabaseHas('purchase_orders', ['id' => $ctx['poId'], 'status' => 'received']);

        $this->patchJson("/api/v1/purchase-orders/{$ctx['poId']}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_cancel_draft_purchase_order(): void
    {
        $ctx = $this->seedDraftPo();

        $this->patchJson("/api/v1/purchase-orders/{$ctx['poId']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
