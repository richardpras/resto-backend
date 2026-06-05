<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SupplierPaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_draft_approve_post_workflow(): void
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

        $create = $this->postJson('/api/v1/supplier-payments', [
            'supplierId' => $master['supplierId'],
            'outletId' => $outlet->id,
            'paymentDate' => now()->toDateString(),
            'paymentMethod' => 'bank_transfer',
            'amount' => 100000,
            'allocations' => [
                ['invoiceId' => $invoiceId, 'allocatedAmount' => 100000],
            ],
        ])->assertCreated();

        $paymentId = (int) $create->json('data.id');
        $create->assertJsonPath('data.status', 'draft');

        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'paid',
            'paid_amount' => 100000,
            'outstanding_amount' => 0,
        ]);
    }

    public function test_void_posted_payment_reverses_allocations(): void
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

        $paymentId = $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 50000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 50000],
        ]);

        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/void")->assertOk();

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'approved',
            'paid_amount' => 0,
        ]);
    }
}
