<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_draft_submit_approve_workflow(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 50],
        ]);

        $create = $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'tax' => 1000,
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'qty' => 20],
            ],
        ])->assertCreated();

        $invoiceId = (int) $create->json('data.id');
        $create->assertJsonPath('data.status', 'draft');

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.outstandingAmount', 201000);
    }

    public function test_partial_invoicing_across_multiple_invoices(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 4],
        ]);
        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 3],
        ]);
        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 3],
        ]);

        $this->assertDatabaseCount('purchase_invoices', 3);
    }

    public function test_void_submitted_invoice(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $invoiceId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 5]],
        ])->json('data.id');

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/void")
            ->assertOk()
            ->assertJsonPath('data.status', 'void');
    }
}
