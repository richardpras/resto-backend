<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingMissingMappingTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        DB::table('accounts')->insert([
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '2140', 'name' => 'GRNI', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'grni', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_grn_posting_fails_when_posting_mappings_missing(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $create = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $this->seedWarehouse((int) $outlet->id),
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
            ],
        ])->assertCreated();

        $grnId = (int) $create->json('data.id');
        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();
        $this->patchJson("/api/v1/goods-receipts/{$grnId}/post")->assertOk();

        $this->postJson("/api/v1/procurement/postings/grn/{$grnId}")
            ->assertStatus(422);

        $this->assertDatabaseMissing('procurement_postings', [
            'source_type' => 'grn',
            'source_id' => $grnId,
            'status' => 'posted',
        ]);
    }
}
