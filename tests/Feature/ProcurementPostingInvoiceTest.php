<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_invoice_approval_creates_grni_ap_journal(): void
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

        $this->assertDatabaseHas('procurement_postings', [
            'source_type' => 'invoice',
            'source_id' => $invoiceId,
            'status' => 'posted',
        ]);

        $journalId = (int) $this->getJson("/api/v1/procurement/postings/status?sourceType=invoice&sourceId={$invoiceId}")
            ->assertOk()
            ->json('data.journalEntryId');

        $entries = \Illuminate\Support\Facades\DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->orderBy('line_no')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertGreaterThan(0, (float) $entries[0]->debit);
        $this->assertEquals((float) $entries[0]->debit, (float) $entries[1]->credit);
    }
}
