<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

/**
 * End-to-end procurement flow focused on document lifecycle and stock mutations.
 * Journal assertions are intentionally omitted (covered in ProcurementPosting* tests).
 */
class ProcurementFullStockFlowTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_full_flow_pr_to_po_to_grn_to_invoice_to_payment_updates_stock(): void
    {
        $outlet = $this->createOutlet('Flow Test Outlet');
        $this->actingAsProcurementUser($outlet);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Flow Vendor',
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Flour',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 5,
            'min' => 1,
            'price' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredientId,
            'outlet_id' => $outlet->id,
            'stock' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialStock = (float) DB::table('inventory_stocks')
            ->where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outlet->id)
            ->value('stock');

        $this->assertSame(5.0, $initialStock);

        // 1. Purchase Request
        $prId = (int) $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'requestedBy' => 'Kitchen Staff',
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 20, 'unit' => 'kg', 'estimatedCost' => 10000],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$prId}/approve")->assertOk();

        // 2. Convert to PO (draft)
        $poId = (int) $this->postJson("/api/v1/purchase-requests/{$prId}/convert", [
            'supplierId' => $supplierId,
        ])->assertOk()->json('data.id');

        $this->assertDatabaseHas('purchase_requests_v2', ['id' => $prId, 'status' => 'converted']);
        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'status' => 'draft']);

        // 3. PO workflow
        $this->patchJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-orders/{$poId}/approve")->assertOk();

        // Stock unchanged before GRN post
        $this->assertSame(
            $initialStock,
            (float) DB::table('inventory_stocks')
                ->where('ingredient_id', $ingredientId)
                ->where('outlet_id', $outlet->id)
                ->value('stock')
        );

        // 4. GRN receive + post
        $warehouseId = $this->seedWarehouse((int) $outlet->id);
        $receivedQty = 20.0;

        $grnId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poId,
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $ingredientId, 'receivedQty' => $receivedQty],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();
        $this->patchJson("/api/v1/goods-receipts/{$grnId}/post")->assertOk();

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $ingredientId,
            'outlet_id' => $outlet->id,
            'type' => 'purchase',
            'quantity' => $receivedQty,
        ]);

        $this->assertSame(
            $initialStock + $receivedQty,
            (float) DB::table('inventory_stocks')
                ->where('ingredient_id', $ingredientId)
                ->where('outlet_id', $outlet->id)
                ->value('stock')
        );

        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'status' => 'received']);

        // 5. Invoice (credit / hutang via due date)
        $invoiceId = (int) $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $poId,
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'dueDate' => now()->addDays(30)->toDateString(),
            'tax' => 0,
            'items' => [
                ['inventoryItemId' => $ingredientId, 'qty' => $receivedQty],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/approve")->assertOk();

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'approved',
            'outstanding_amount' => 200000,
        ]);

        // Stock unchanged after invoice (no second stock mutation)
        $this->assertSame(
            $initialStock + $receivedQty,
            (float) DB::table('inventory_stocks')
                ->where('ingredient_id', $ingredientId)
                ->where('outlet_id', $outlet->id)
                ->value('stock')
        );

        // 6. Supplier payment (hutang)
        $paymentId = (int) $this->postJson('/api/v1/supplier-payments', [
            'supplierId' => $supplierId,
            'outletId' => $outlet->id,
            'paymentDate' => now()->toDateString(),
            'paymentMethod' => 'bank_transfer',
            'amount' => 200000,
            'allocations' => [
                ['invoiceId' => $invoiceId, 'allocatedAmount' => 200000],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/approve")->assertOk();
        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/post")->assertOk();

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => 'paid',
            'paid_amount' => 200000,
            'outstanding_amount' => 0,
        ]);

        // Stock still unchanged after payment
        $this->assertSame(
            $initialStock + $receivedQty,
            (float) DB::table('inventory_stocks')
                ->where('ingredient_id', $ingredientId)
                ->where('outlet_id', $outlet->id)
                ->value('stock')
        );
    }

    public function test_stock_not_mutated_before_grn_post(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        DB::table('inventory_stocks')->updateOrInsert(
            ['ingredient_id' => $master['ingredientId'], 'outlet_id' => $outlet->id],
            ['stock' => 10, 'created_at' => now(), 'updated_at' => now()]
        );

        $warehouseId = $this->seedWarehouse((int) $outlet->id);

        $grnId = (int) $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $master['poId'],
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 15],
            ],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();

        $this->assertDatabaseMissing('stock_movements', [
            'inventory_item_id' => $master['ingredientId'],
            'type' => 'purchase',
        ]);

        $this->assertSame(
            10.0,
            (float) DB::table('inventory_stocks')
                ->where('ingredient_id', $master['ingredientId'])
                ->where('outlet_id', $outlet->id)
                ->value('stock')
        );
    }
}
