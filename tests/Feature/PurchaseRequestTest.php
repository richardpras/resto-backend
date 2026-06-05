<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    /** @return array{outletId:int,ingredientId:int} */
    private function seedPrContext(): array
    {
        $outlet = $this->createOutlet();
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Flour',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 5,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['outletId' => (int) $outlet->id, 'ingredientId' => $ingredientId];
    }

    public function test_create_purchase_request_draft(): void
    {
        $this->actingAsProcurementUser();
        $ctx = $this->seedPrContext();

        $response = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $ctx['outletId'],
            'requestedBy' => 'Chef A',
            'notes' => 'Weekly stock',
            'items' => [
                [
                    'inventoryItemId' => $ctx['ingredientId'],
                    'quantity' => 10,
                    'unit' => 'kg',
                    'estimatedCost' => 15000,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.requestedBy', 'Chef A');
        $response->assertJsonPath('data.items.0.qty', 10);

        $this->assertDatabaseHas('purchase_requests_v2', [
            'outlet_id' => $ctx['outletId'],
            'status' => 'draft',
            'requested_by' => 'Chef A',
        ]);
    }

    public function test_update_draft_purchase_request(): void
    {
        $this->actingAsProcurementUser();
        $ctx = $this->seedPrContext();

        $created = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $ctx['outletId'],
            'requestedBy' => 'Chef A',
            'items' => [
                ['inventoryItemId' => $ctx['ingredientId'], 'quantity' => 5, 'unit' => 'kg'],
            ],
        ])->assertCreated();

        $id = $created->json('data.id');

        $this->patchJson("/api/v1/purchase-requests/{$id}", [
            'notes' => 'Updated note',
            'items' => [
                ['inventoryItemId' => $ctx['ingredientId'], 'quantity' => 8, 'unit' => 'kg'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Updated note')
            ->assertJsonPath('data.items.0.qty', 8);
    }

    public function test_submit_purchase_request(): void
    {
        $this->actingAsProcurementUser();
        $ctx = $this->seedPrContext();

        $id = $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $ctx['outletId'],
            'items' => [
                ['inventoryItemId' => $ctx['ingredientId'], 'quantity' => 3, 'unit' => 'kg'],
            ],
        ])->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('purchase_requests_v2', [
            'id' => $id,
            'status' => 'submitted',
        ]);
        $this->assertNotNull(DB::table('purchase_requests_v2')->where('id', $id)->value('submitted_at'));
    }

    public function test_cannot_submit_empty_purchase_request(): void
    {
        $this->actingAsProcurementUser();
        $ctx = $this->seedPrContext();

        $id = DB::table('purchase_requests_v2')->insertGetId([
            'request_no' => 'PR-EMPTY',
            'outlet_id' => $ctx['outletId'],
            'requested_by' => 'Chef',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/purchase-requests/{$id}/submit")
            ->assertStatus(422);
    }
}
