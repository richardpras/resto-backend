<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingPaymentTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_payment_post_creates_ap_cash_journal(): void
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

        $paymentId = $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 50000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 50000],
        ]);

        $this->assertDatabaseHas('procurement_postings', [
            'source_type' => 'supplier_payment',
            'source_id' => $paymentId,
            'status' => 'posted',
            'amount' => 50000,
        ]);

        $journalId = (int) $this->getJson("/api/v1/procurement/postings/status?sourceType=supplier_payment&sourceId={$paymentId}")
            ->assertOk()
            ->json('data.journalEntryId');

        $entries = \Illuminate\Support\Facades\DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->orderBy('line_no')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertEquals(50000, (float) $entries[0]->debit);
        $this->assertEquals(50000, (float) $entries[1]->credit);
    }
}
