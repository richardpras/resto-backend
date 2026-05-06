<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PurchaseInvoicePaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_supplier_payment_reduces_ap_creates_journal_and_updates_invoice_status(): void
    {
        Passport::actingAs(User::factory()->create());

        $cashId = DB::table('accounts')->insertGetId([
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $apId = DB::table('accounts')->insertGetId([
            'code' => '2100',
            'name' => 'Accounts Payable',
            'type' => 'liability',
            'subtype' => 'short_term_liability',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'PT Supplier',
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
            'status' => 'completed',
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

        $invoiceId = DB::table('purchase_invoices')->insertGetId([
            'purchase_order_id' => $poId,
            'goods_receiving_note_id' => $grId,
            'number' => 'INV-0001',
            'invoice_date' => now()->toDateString(),
            'total' => 21000,
            'tax' => 1000,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $partial = $this->postJson("/api/v1/purchase-invoices/{$invoiceId}/payments", [
            'date' => now()->toDateString(),
            'amount' => 10000,
            'paymentMethod' => 'cash',
        ]);
        $partial->assertCreated();
        $partial->assertJsonPath('data.status', 'partial');

        $this->assertDatabaseHas('purchase_invoice_payments', [
            'purchase_invoice_id' => $invoiceId,
            'amount' => 10000.00,
            'payment_method' => 'cash',
        ]);

        /** @var Journal $paymentJournal */
        $paymentJournal = Journal::query()
            ->where('source_type', 'purchase_invoice_payment')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertDatabaseHas('journal_entries', [
            'journal_id' => $paymentJournal->id,
            'account_id' => $apId,
            'debit' => 10000.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'journal_id' => $paymentJournal->id,
            'account_id' => $cashId,
            'debit' => 0.00,
            'credit' => 10000.00,
        ]);

        $full = $this->postJson("/api/v1/purchase-invoices/{$invoiceId}/payments", [
            'date' => now()->toDateString(),
            'amount' => 11000,
            'paymentMethod' => 'cash',
        ]);
        $full->assertCreated();
        $full->assertJsonPath('data.status', 'paid');

        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
        $this->assertSame('paid', $invoice->status);
    }
}
