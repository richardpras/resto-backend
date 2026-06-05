<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseInvoiceAuditTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_lifecycle_actions_write_audit_logs(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $invoiceId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 5]],
        ])->json('data.id');

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'purchase_invoice_created',
            'entity_type' => 'purchase_invoice',
            'entity_id' => $invoiceId,
        ]);

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/submit")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'purchase_invoice_submitted',
            'entity_id' => $invoiceId,
        ]);

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/approve")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'purchase_invoice_approved',
            'entity_id' => $invoiceId,
        ]);

        $voidId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 2]],
        ])->json('data.id');

        $this->patchJson("/api/v1/purchase-invoices/{$voidId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-invoices/{$voidId}/void")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'purchase_invoice_voided',
            'entity_id' => $voidId,
        ]);
    }
}
