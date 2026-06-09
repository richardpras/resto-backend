<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementPostingAuditTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_grn_posting_writes_audit_event(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $postingId = (int) \Illuminate\Support\Facades\DB::table('procurement_postings')
            ->where('source_type', 'grn')
            ->where('source_id', $grnId)
            ->value('id');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'procurement_posting_created',
            'entity_type' => 'procurement_posting',
            'entity_id' => $postingId,
            'actor_user_id' => (int) $user->id,
        ]);

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'grn_posted_to_accounting',
            'entity_type' => 'procurement_posting',
            'entity_id' => $postingId,
        ]);
    }
}
