<?php

namespace Tests\Feature;

use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseInvoiceApiTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_creating_purchase_invoice_starts_as_draft_without_ap_journal(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 2],
        ]);

        $response = $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'tax' => 1000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.poReference', 'PO-1');
        $response->assertJsonPath('data.grReference', 'GRN-0001');

        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoice->id,
            'purchase_order_id' => $master['poId'],
            'goods_receiving_note_id' => $grnId,
            'status' => 'draft',
        ]);

        $this->assertDatabaseMissing('journals', [
            'source_type' => 'purchase_invoice',
            'source_id' => (string) $invoice->id,
        ]);
    }
}
