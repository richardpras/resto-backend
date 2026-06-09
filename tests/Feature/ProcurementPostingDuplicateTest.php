<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingDuplicateTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_duplicate_grn_posting_is_blocked(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $this->postJson("/api/v1/procurement/postings/grn/{$grnId}")
            ->assertStatus(422);

        $this->assertDatabaseCount('procurement_postings', 1);
    }

    public function test_duplicate_invoice_posting_is_blocked(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 5],
        ]);

        $this->assertDatabaseHas('procurement_postings', [
            'source_type' => 'invoice',
            'source_id' => $invoiceId,
            'status' => 'posted',
        ]);

        $this->postJson("/api/v1/procurement/postings/invoice/{$invoiceId}")
            ->assertStatus(422);
    }

    public function test_duplicate_payment_posting_is_blocked(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 5],
        ]);

        $paymentId = $this->createPostedSupplierPayment(
            $master['supplierId'],
            (int) $outlet->id,
            50000,
            [[
                'invoiceId' => $invoiceId,
                'allocatedAmount' => 50000,
            ]],
        );

        $this->assertDatabaseHas('procurement_postings', [
            'source_type' => 'supplier_payment',
            'source_id' => $paymentId,
            'status' => 'posted',
        ]);

        $this->postJson("/api/v1/procurement/postings/payment/{$paymentId}")
            ->assertStatus(422);
    }
}
