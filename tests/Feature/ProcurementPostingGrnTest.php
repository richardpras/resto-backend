<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingGrnTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_grn_post_creates_inventory_grni_journal(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $this->assertDatabaseHas('procurement_postings', [
            'source_type' => 'grn',
            'source_id' => $grnId,
            'status' => 'posted',
            'amount' => 100000,
        ]);

        $journalId = (int) $this->getJson("/api/v1/procurement/postings/status?sourceType=grn&sourceId={$grnId}")
            ->assertOk()
            ->json('data.journalEntryId');

        $entries = \Illuminate\Support\Facades\DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->orderBy('line_no')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertEquals(100000, (float) $entries[0]->debit);
        $this->assertEquals(100000, (float) $entries[1]->credit);
    }
}
