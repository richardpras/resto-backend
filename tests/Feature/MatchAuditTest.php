<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class MatchAuditTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_revalidate_logs_audit_event(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 10],
        ]);

        $this->postJson('/api/v1/procurement/match-results/revalidate', [
            'invoiceId' => $invoiceId,
        ])->assertOk();

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'procurement_match_revalidated',
            'entity_type' => 'purchase_invoice',
            'entity_id' => $invoiceId,
            'actor_user_id' => (int) $user->id,
        ]);
    }
}

