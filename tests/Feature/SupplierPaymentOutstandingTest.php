<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SupplierPaymentOutstandingTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_full_payment_clears_outstanding(): void
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

        $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 40000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 40000],
        ]);
        $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 60000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 60000],
        ]);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'paid',
            'outstanding_amount' => 0,
        ]);
    }
}
