<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SupplierPaymentAuditTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_payment_lifecycle_writes_audit_logs(): void
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

        $paymentId = (int) $this->postJson('/api/v1/supplier-payments', [
            'supplierId' => $master['supplierId'],
            'outletId' => $outlet->id,
            'paymentDate' => now()->toDateString(),
            'amount' => 50000,
            'allocations' => [['invoiceId' => $invoiceId, 'allocatedAmount' => 50000]],
        ])->json('data.id');

        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'supplier_payment_created', 'entity_id' => $paymentId]);
        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/approve")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'supplier_payment_approved', 'entity_id' => $paymentId]);
        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/post")->assertOk();
        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'supplier_payment_posted', 'entity_id' => $paymentId]);
        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'supplier_payment_allocation_created', 'entity_id' => $paymentId]);
    }
}
