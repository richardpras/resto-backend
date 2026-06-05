<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseInvoicePayablesTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_payables_endpoint_returns_supplier_summary(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 4],
        ]);

        $response = $this->getJson('/api/v1/procurement/payables?outletId='.$outlet->id)->assertOk();
        $response->assertJsonPath('data.0.supplierId', (string) $master['supplierId']);
        $response->assertJsonPath('data.0.invoiceCount', 1);
        $response->assertJsonPath('data.0.outstandingBalance', 40000);
    }

    public function test_procurement_summary_includes_invoice_counters(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 20],
        ]);

        $draftId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 2]],
        ])->json('data.id');

        $submittedId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 3]],
        ])->json('data.id');
        $this->patchJson("/api/v1/purchase-invoices/{$submittedId}/submit")->assertOk();

        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 5],
        ]);

        $response = $this->getJson('/api/v1/procurement/summary?outletId='.$outlet->id)->assertOk();
        $response->assertJsonPath('data.draftInvoices', 1);
        $response->assertJsonPath('data.submittedInvoices', 1);
        $response->assertJsonPath('data.approvedInvoices', 1);
        $response->assertJsonPath('data.outstandingPayables', 50000);

        $this->patchJson("/api/v1/purchase-invoices/{$draftId}/void")->assertOk();
    }
}
