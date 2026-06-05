<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ThreeWayMatchTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_perfect_match_creates_matched_result(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
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
            'match_status' => 'matched',
        ]);
    }
}

