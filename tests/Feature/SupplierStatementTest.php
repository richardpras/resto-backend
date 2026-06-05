<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SupplierStatementTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_supplier_statement_returns_invoices_payments_and_outstanding(): void
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

        $response = $this->getJson('/api/v1/procurement/supplier-statement?supplierId='.$master['supplierId'].'&outletId='.$outlet->id)->assertOk();
        $response->assertJsonPath('data.supplierId', (string) $master['supplierId']);
        $response->assertJsonPath('data.totalPaid', 40000);
        $response->assertJsonPath('data.outstanding', 60000);
        $this->assertCount(1, $response->json('data.invoices'));
        $this->assertCount(1, $response->json('data.payments'));
    }
}
