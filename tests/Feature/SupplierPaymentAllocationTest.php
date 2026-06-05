<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class SupplierPaymentAllocationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_partial_payment_updates_invoice_status(): void
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

        $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 40000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 40000],
        ]);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'partially_paid',
            'paid_amount' => 40000,
            'outstanding_amount' => 60000,
        ]);
    }

    public function test_multi_invoice_payment_allocation(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 20],
        ]);

        $invoiceA = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 8],
        ]);
        $invoiceB = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 7],
        ]);

        $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 150000, [
            ['invoiceId' => $invoiceA, 'allocatedAmount' => 80000],
            ['invoiceId' => $invoiceB, 'allocatedAmount' => 70000],
        ]);

        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoiceA, 'status' => 'paid']);
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoiceB, 'status' => 'paid']);
    }

    public function test_over_allocation_is_blocked(): void
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

        $this->postJson('/api/v1/supplier-payments', [
            'supplierId' => $master['supplierId'],
            'outletId' => $outlet->id,
            'paymentDate' => now()->toDateString(),
            'amount' => 100000,
            'allocations' => [
                ['invoiceId' => $invoiceId, 'allocatedAmount' => 60000],
            ],
        ])->assertStatus(422);
    }
}
