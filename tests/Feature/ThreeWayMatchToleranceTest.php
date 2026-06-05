<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ThreeWayMatchToleranceTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_within_tolerance_results_in_matched_with_tolerance(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        DB::table('procurement_match_configs')->insert([
            'outlet_id' => (int) $outlet->id,
            'quantity_tolerance_percent' => 0,
            'price_tolerance_percent' => 3,
            'amount_tolerance_percent' => 3,
            'auto_approve_within_tolerance' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10, 'unitCost' => 10300],
        ]);

        $invoiceId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'qty' => 10],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/approve")->assertOk();

        $this->assertDatabaseHas('procurement_match_results', [
            'invoice_id' => $invoiceId,
            'match_status' => 'matched_with_tolerance',
        ]);
    }
}

