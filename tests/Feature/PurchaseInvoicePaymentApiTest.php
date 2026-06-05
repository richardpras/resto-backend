<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseInvoicePaymentApiTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_legacy_invoice_payment_endpoint_redirects_to_supplier_payments(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 2],
        ]);
        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [], 1000);

        $this->postJson("/api/v1/purchase-invoices/{$invoiceId}/payments", [
            'date' => now()->toDateString(),
            'amount' => 10000,
            'paymentMethod' => 'cash',
        ])->assertStatus(422);
    }

    public function test_supplier_payment_updates_invoice_without_accounting_journal(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $this->seedAccountingAccounts();
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 2],
        ]);
        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [], 1000);

        $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 10000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 10000],
        ]);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'partially_paid',
            'paid_amount' => 10000,
        ]);

        $this->assertDatabaseMissing('journals', [
            'source_type' => 'purchase_invoice_payment',
        ]);
    }
}
