<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseRequestConversionTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    /** @return array{outletId:int,ingredientId:int,supplierId:int,prId:int} */
    private function seedApprovedPr(): array
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Vendor Convert',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Oil',
            'type' => 'ingredient',
            'unit' => 'L',
            'stock' => 2,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 6, 'unit' => 'L', 'estimatedCost' => 25000],
            ],
        ])->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$prId}/approve")->assertOk();

        return [
            'outletId' => (int) $outlet->id,
            'ingredientId' => $ingredientId,
            'supplierId' => $supplierId,
            'prId' => (int) $prId,
        ];
    }

    public function test_approved_purchase_request_converts_to_purchase_order(): void
    {
        $ctx = $this->seedApprovedPr();

        $response = $this->postJson("/api/v1/purchase-requests/{$ctx['prId']}/convert", [
            'supplierId' => $ctx['supplierId'],
        ]);

        $response->assertOk();
        $poId = $response->json('data.id');
        $response->assertJsonPath('data.supplierId', (string) $ctx['supplierId']);
        $response->assertJsonPath('data.referencePR', 'PR-0001');
        $response->assertJsonPath('purchaseRequest.status', 'converted');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'purchase_request_id' => $ctx['prId'],
            'supplier_id' => $ctx['supplierId'],
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $poId,
            'ingredient_id' => $ctx['ingredientId'],
            'ordered_qty' => 6,
            'is_from_pr' => 1,
        ]);
        $this->assertDatabaseHas('purchase_requests_v2', [
            'id' => $ctx['prId'],
            'status' => 'converted',
        ]);
    }

    public function test_cannot_convert_purchase_request_twice(): void
    {
        $ctx = $this->seedApprovedPr();

        $this->postJson("/api/v1/purchase-requests/{$ctx['prId']}/convert", [
            'supplierId' => $ctx['supplierId'],
        ])->assertOk();

        $this->postJson("/api/v1/purchase-requests/{$ctx['prId']}/convert", [
            'supplierId' => $ctx['supplierId'],
        ])->assertStatus(422);
    }

    public function test_cannot_convert_draft_purchase_request(): void
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
            'name' => 'Butter',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 1, 'unit' => 'kg'],
            ],
        ])->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/convert", [
            'supplierId' => $supplierId,
        ])->assertStatus(422);
    }
}
