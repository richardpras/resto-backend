<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseInvoiceValidationTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_cannot_invoice_draft_grn(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $grnId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10]],
        ])->json('data.id');

        $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 5]],
        ])->assertStatus(422);
    }

    public function test_cannot_invoice_received_grn(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $grnId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10]],
        ])->json('data.id');
        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();

        $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 5]],
        ])->assertStatus(422);
    }

    public function test_over_invoice_is_blocked(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);

        $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'items' => [['inventoryItemId' => $master['ingredientId'], 'qty' => 15]],
        ])->assertStatus(422);
    }

    public function test_cannot_approve_zero_total_invoice(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'outlet_id' => $outlet->id,
            'purchase_order_id' => $master['poId'],
            'goods_receiving_note_id' => $grnId,
            'supplier_id' => $master['supplierId'],
            'number' => 'INV-TEST',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'total' => 0,
            'paid_amount' => 0,
            'outstanding_amount' => 0,
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/approve")->assertStatus(422);
    }
}
