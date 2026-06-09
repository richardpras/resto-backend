<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingReversalTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_grn_posting_can_be_reversed(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $postingId = (int) $this->getJson("/api/v1/procurement/postings/status?sourceType=grn&sourceId={$grnId}")
            ->json('data.id');

        $originalJournalId = (int) \Illuminate\Support\Facades\DB::table('procurement_postings')
            ->where('id', $postingId)
            ->value('journal_entry_id');

        $this->postJson("/api/v1/procurement/postings/{$postingId}/reverse", ['notes' => 'Test reversal'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'procurement_posting_reversed',
            'entity_type' => 'procurement_posting',
            'entity_id' => $postingId,
        ]);

        $this->assertDatabaseHas('procurement_postings', [
            'id' => $postingId,
            'status' => 'reversed',
        ]);

        $this->assertDatabaseHas('journals', [
            'id' => $originalJournalId,
        ]);
        $this->assertNotNull(
            \Illuminate\Support\Facades\DB::table('journals')->where('id', $originalJournalId)->value('reversal_journal_id')
        );

    }

    public function test_invoice_and_payment_postings_can_be_reversed(): void
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

        $invoicePostingId = (int) $this->getJson("/api/v1/procurement/postings/status?sourceType=invoice&sourceId={$invoiceId}")
            ->json('data.id');
        $originalInvoiceJournalId = (int) \Illuminate\Support\Facades\DB::table('procurement_postings')
            ->where('id', $invoicePostingId)
            ->value('journal_entry_id');

        $this->postJson("/api/v1/procurement/postings/{$invoicePostingId}/reverse", ['notes' => 'Test invoice reversal'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'procurement_posting_reversed',
            'entity_type' => 'procurement_posting',
            'entity_id' => $invoicePostingId,
        ]);

        $this->assertNotNull(
            \Illuminate\Support\Facades\DB::table('journals')->where('id', $originalInvoiceJournalId)->value('reversal_journal_id')
        );

        $paymentId = $this->createPostedSupplierPayment(
            $master['supplierId'],
            (int) $outlet->id,
            50000,
            [[
                'invoiceId' => $invoiceId,
                'allocatedAmount' => 50000,
            ]],
        );

        $paymentPostingId = (int) $this->getJson("/api/v1/procurement/postings/status?sourceType=supplier_payment&sourceId={$paymentId}")
            ->json('data.id');
        $originalPaymentJournalId = (int) \Illuminate\Support\Facades\DB::table('procurement_postings')
            ->where('id', $paymentPostingId)
            ->value('journal_entry_id');

        $this->postJson("/api/v1/procurement/postings/{$paymentPostingId}/reverse", ['notes' => 'Test payment reversal'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'procurement_posting_reversed',
            'entity_type' => 'procurement_posting',
            'entity_id' => $paymentPostingId,
        ]);

        $this->assertNotNull(
            \Illuminate\Support\Facades\DB::table('journals')->where('id', $originalPaymentJournalId)->value('reversal_journal_id')
        );
    }
}
