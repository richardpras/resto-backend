<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PaymentMatchValidationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_payment_post_blocked_when_invoice_mismatch(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 10],
        ]);

        // Force latest result to mismatch (e.g. config change / manual review).
        DB::table('procurement_match_results')->insert([
            'purchase_order_id' => $master['poId'],
            'goods_receipt_id' => $grnId,
            'invoice_id' => $invoiceId,
            'match_status' => 'mismatch',
            'qty_difference' => 0,
            'price_difference' => 5,
            'amount_difference' => 50000,
            'matched_at' => now(),
            'matched_by' => null,
            'notes' => 'Test mismatch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = (int) $this->postJson('/api/v1/supplier-payments', [
            'supplierId' => $master['supplierId'],
            'outletId' => (int) $outlet->id,
            'paymentDate' => now()->toDateString(),
            'paymentMethod' => 'cash',
            'amount' => 50000,
            'allocations' => [
                ['invoiceId' => $invoiceId, 'allocatedAmount' => 50000],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/approve")->assertOk();
        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/post")
            ->assertStatus(422)
            ->assertSeeText('Invoice must pass three-way matching before payment.');
    }
}

