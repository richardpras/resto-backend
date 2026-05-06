<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PurchaseInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_creating_purchase_invoice_creates_ap_journal_and_defaults_unpaid(): void
    {
        Passport::actingAs(User::factory()->create());

        DB::table('accounts')->insert([
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'created_at' => now(), 'updated_at' => now()],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'PT Vendor',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'name' => 'Rice',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 10,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $prId = DB::table('purchase_requests')->insertGetId([
            'number' => 'PR-0001',
            'status' => 'approved',
            'request_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'purchase_request_id' => $prId,
            'supplier_id' => $supplierId,
            'number' => 'PO-0001',
            'status' => 'sent',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'ingredient_id' => $ingredientId,
            'ordered_qty' => 2,
            'received_qty' => 2,
            'unit_price' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $grId = DB::table('goods_receiving_notes')->insertGetId([
            'purchase_order_id' => $poId,
            'number' => 'GRN-0001',
            'received_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('goods_receiving_note_items')->insert([
            'goods_receiving_note_id' => $grId,
            'purchase_order_item_id' => $poItemId,
            'ingredient_id' => $ingredientId,
            'received_qty' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/purchase-invoices', [
            'purchaseOrderId' => $poId,
            'goodsReceiptId' => $grId,
            'date' => now()->toDateString(),
            'tax' => 1000,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'unpaid');
        $response->assertJsonPath('data.poReference', 'PO-0001');
        $response->assertJsonPath('data.grReference', 'GRN-0001');

        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoice->id,
            'purchase_order_id' => $poId,
            'goods_receiving_note_id' => $grId,
            'status' => 'unpaid',
        ]);

        /** @var Journal $journal */
        $journal = Journal::query()->where('source_type', 'purchase_invoice')->where('source_id', (string) $invoice->id)->firstOrFail();
        $this->assertDatabaseHas('journal_entries', [
            'journal_id' => $journal->id,
            'debit' => 21000.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'journal_id' => $journal->id,
            'debit' => 0.00,
            'credit' => 21000.00,
        ]);
    }
}
