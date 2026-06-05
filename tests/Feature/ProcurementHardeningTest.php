<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementHardeningTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_purchase_routes_require_purchase_manage_permission(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->getJson('/api/v1/goods-receipts')->assertForbidden();
        $this->getJson('/api/v1/purchase-invoices')->assertForbidden();
        $this->getJson('/api/v1/procurement/summary')->assertForbidden();
    }

    public function test_purchase_order_creation_sets_outlet_id_and_writes_audit_log(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $response = $this->postJson('/api/v1/purchase-orders', [
            'outletId' => $outlet->id,
            'date' => now()->toDateString(),
            'supplierId' => $master['supplierId'],
            'status' => 'draft',
            'items' => [
                [
                    'inventoryItemId' => $master['ingredientId'],
                    'qty' => 5,
                    'unit' => 'kg',
                    'price' => 12000,
                ],
            ],
        ]);

        $response->assertCreated();
        $poId = (int) $response->json('data.id');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'outlet_id' => $outlet->id,
        ]);
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'purchase_order.created',
            'entity_type' => 'purchase_order',
            'entity_id' => $poId,
            'outlet_id' => $outlet->id,
        ]);
    }

    public function test_purchase_orders_are_scoped_by_outlet(): void
    {
        $outletA = $this->createOutlet('Outlet A');
        $outletB = $this->createOutlet('Outlet B');
        $this->actingAsProcurementUser($outletA);

        $masterA = $this->seedProcurementMasterData((int) $outletA->id, 'A');
        $masterB = $this->seedProcurementMasterData((int) $outletB->id, 'B');

        $response = $this->getJson('/api/v1/purchase-orders?outletId='.$outletA->id);
        $response->assertOk();

        $numbers = collect($response->json('data'))->pluck('poNumber')->all();
        $this->assertContains('PO-A', $numbers);
        $this->assertNotContains('PO-B', $numbers);
    }

    public function test_grn_uses_po_unit_price_and_stores_cost_audit_fields(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            [
                'inventoryItemId' => $master['ingredientId'],
                'receivedQty' => 40,
                'unitCost' => 9500,
            ],
        ]);

        $this->assertDatabaseHas('goods_receiving_note_items', [
            'ingredient_id' => $master['ingredientId'],
            'received_qty' => 40,
            'original_po_cost' => 10000,
            'actual_received_cost' => 9500,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $master['ingredientId'],
            'outlet_id' => $outlet->id,
            'type' => 'purchase',
            'unit_cost' => 9500,
        ]);

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'goods_receipt_created',
            'entity_type' => 'goods_receiving_note',
            'entity_id' => $grnId,
        ]);
    }

    public function test_invoice_cannot_exceed_remaining_received_quantity(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $this->seedAccountingAccounts();
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 100],
        ]);

        $this->createApprovedInvoice($master['poId'], $grId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 80],
        ]);

        $secondInvoice = $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $master['poId'],
            'goodsReceiptId' => $grId,
            'date' => now()->toDateString(),
            'tax' => 0,
            'items' => [
                ['inventoryItemId' => $master['ingredientId'], 'qty' => 30],
            ],
        ]);
        $secondInvoice->assertStatus(422);
    }

    public function test_procurement_summary_endpoint_returns_counts(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $this->seedProcurementMasterData((int) $outlet->id);

        $response = $this->getJson('/api/v1/procurement/summary?outletId='.$outlet->id);
        $response->assertOk();
        $response->assertJsonPath('data.totalSuppliers', 1);
        $response->assertJsonPath('data.totalPurchaseOrders', 1);
        $response->assertJsonPath('data.totalGoodsReceipts', 0);
        $response->assertJsonPath('data.totalPurchaseInvoices', 0);
        $response->assertJsonPath('data.totalPurchasePayments', 0);
    }

    public function test_invoice_creation_writes_audit_log_and_items(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $this->seedAccountingAccounts();
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 2],
        ]);

        $invoiceId = $this->createApprovedInvoice($master['poId'], $grId, [], 1000);

        $this->assertDatabaseHas('purchase_invoice_items', [
            'purchase_invoice_id' => $invoiceId,
            'ingredient_id' => $master['ingredientId'],
            'invoiced_qty' => 2,
        ]);
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'purchase_invoice_created',
            'entity_type' => 'purchase_invoice',
            'entity_id' => $invoiceId,
        ]);
    }
}
