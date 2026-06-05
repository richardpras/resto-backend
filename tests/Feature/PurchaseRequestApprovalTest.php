<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseRequestApprovalTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    /** @return array{outletId:int,ingredientId:int,prId:int} */
    private function seedSubmittedPr(): array
    {
        $outlet = $this->createOutlet();
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Sugar',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 5,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->actingAsProcurementUser($outlet);
        $prId = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 4, 'unit' => 'kg'],
            ],
        ])->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();

        return ['outletId' => (int) $outlet->id, 'ingredientId' => $ingredientId, 'prId' => (int) $prId, 'userId' => $user->id];
    }

    public function test_approve_submitted_purchase_request(): void
    {
        $ctx = $this->seedSubmittedPr();

        $this->postJson("/api/v1/purchase-requests/{$ctx['prId']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('purchase_requests_v2', [
            'id' => $ctx['prId'],
            'status' => 'approved',
            'approved_by' => $ctx['userId'],
        ]);
    }

    public function test_reject_submitted_purchase_request(): void
    {
        $ctx = $this->seedSubmittedPr();

        $this->postJson("/api/v1/purchase-requests/{$ctx['prId']}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertNotNull(DB::table('purchase_requests_v2')->where('id', $ctx['prId'])->value('rejected_at'));
    }

    public function test_cannot_approve_draft_purchase_request(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Salt',
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
                ['inventoryItemId' => $ingredientId, 'quantity' => 2, 'unit' => 'kg'],
            ],
        ])->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/approve")
            ->assertStatus(422);
    }
}
