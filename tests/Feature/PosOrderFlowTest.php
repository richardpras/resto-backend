<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_order_creates_unpaid_order_and_kitchen_print_job(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'code' => 'POS-CONFIRM-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 30000],
            ],
            'subtotal' => 30000,
            'tax' => 3000,
            'total' => 33000,
            'payments' => [],
            'tableNumber' => 'table-1',
            'confirmedAt' => now()->toISOString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'confirmed');
        $response->assertJsonPath('data.paymentStatus', 'unpaid');

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('print_jobs', [
            'source_type' => 'order',
            'source_id' => $orderId,
            'type' => 'kitchen',
            'status' => 'pending',
        ]);
    }

    public function test_index_filters_can_query_confirmed_unpaid_dine_in_orders_for_cashier(): void
    {
        $matchedOrder = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'code' => 'POS-UNPAID-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 30000],
            ],
            'subtotal' => 30000,
            'tax' => 3000,
            'total' => 33000,
            'payments' => [],
        ]);
        $matchedOrder->assertCreated();

        $notMatchedOrder = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'code' => 'POS-PAID-1',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'items' => [
                ['id' => '102', 'name' => 'Mie Goreng', 'qty' => 1, 'price' => 20000],
            ],
            'subtotal' => 20000,
            'tax' => 2000,
            'total' => 22000,
            'payments' => [
                ['method' => 'cash', 'amount' => 22000],
            ],
        ]);
        $notMatchedOrder->assertCreated();

        $response = $this->getJson('/api/v1/orders?tenantId=1&perPage=10&paymentStatus=unpaid&orderType=Dine-in&status=confirmed&source=pos');

        $response->assertOk();
        $response->assertJsonPath('meta.perPage', 10);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'POS-UNPAID-1');
        $response->assertJsonPath('data.0.paymentStatus', 'unpaid');
        $response->assertJsonPath('data.0.orderType', 'Dine-in');
        $response->assertJsonPath('data.0.status', 'confirmed');
        $response->assertJsonPath('data.0.source', 'pos');
    }

    public function test_paying_order_creates_balanced_sales_journal_and_deducts_stock(): void
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Chicken',
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 50,
            'min' => 5,
        ]);

        $cashId = DB::table('accounts')->insertGetId([
            'code' => '1001',
            'name' => 'Cash',
            'type' => 'asset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revenueId = DB::table('accounts')->insertGetId([
            'code' => '4001',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = DB::table('menu_items')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => 1,
            'name' => 'Fried Chicken',
            'price' => 25000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuId,
            'ingredient_id' => $ingredient->id,
            'qty' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'POS-PAY-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $menuId, 'name' => 'Fried Chicken', 'qty' => 2, 'price' => 25000],
            ],
            'subtotal' => 50000,
            'tax' => 5000,
            'total' => 55000,
            'payments' => [],
        ]);

        $create->assertCreated();
        $orderId = $create->json('data.id');

        $partialPay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 30000],
            ],
            'cashAccountCode' => '1001',
            'revenueAccountCode' => '4001',
        ]);

        $partialPay->assertOk();
        $partialPay->assertJsonPath('data.paymentStatus', 'partial');
        $partialPay->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'stock' => 50.00,
        ]);
        $this->assertDatabaseMissing('journals', [
            'source_type' => 'order_payment',
            'source_id' => (int) $orderId,
        ]);

        $finalPay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'qris', 'amount' => 25000],
            ],
            'cashAccountCode' => '1001',
            'revenueAccountCode' => '4001',
        ]);

        $finalPay->assertOk();
        $finalPay->assertJsonPath('data.paymentStatus', 'paid');
        $finalPay->assertJsonPath('data.status', 'completed');

        $unpaidFilter = $this->getJson('/api/v1/orders?tenantId=1&paymentStatus=unpaid&orderType=Dine-in&status=confirmed&source=pos');
        $unpaidFilter->assertOk();
        $this->assertCount(
            0,
            collect($unpaidFilter->json('data'))->where('id', (int) $orderId)
        );

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'stock' => 46.00,
        ]);

        $this->assertDatabaseHas('journals', [
            'source_type' => 'order_payment',
            'source_id' => (int) $orderId,
        ]);

        $journalId = DB::table('journals')->where('source_type', 'order_payment')->where('source_id', (int) $orderId)->value('id');
        $totalDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $totalCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame($totalDebit, $totalCredit);

        $this->assertDatabaseHas('journal_entries', [
            'journal_id' => $journalId,
            'account_id' => $cashId,
            'debit' => 55000.00,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'journal_id' => $journalId,
            'account_id' => $revenueId,
            'credit' => 55000.00,
        ]);
    }

    public function test_payment_allocations_are_accepted_and_returned(): void
    {
        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'POS-ALLOC-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 2, 'price' => 20000],
                ['id' => '102', 'name' => 'Mie Goreng', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 50000,
            'tax' => 0,
            'total' => 50000,
            'payments' => [],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');
        $orderItems = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get(['id']);

        $pay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 25000,
                    'allocations' => [
                        ['orderItemId' => (int) $orderItems[0]->id, 'qty' => 1, 'amount' => 20000],
                        ['orderItemId' => (int) $orderItems[1]->id, 'qty' => 0.5, 'amount' => 5000],
                    ],
                ],
            ],
        ]);

        $pay->assertOk();
        $pay->assertJsonPath('data.payments.0.allocations.0.orderItemId', (int) $orderItems[0]->id);
        $this->assertEquals(1.0, (float) $pay->json('data.payments.0.allocations.0.qty'));
        $this->assertEquals(20000.0, (float) $pay->json('data.payments.0.allocations.0.amount'));
        $pay->assertJsonPath('data.payments.0.allocations.1.orderItemId', (int) $orderItems[1]->id);
    }

    public function test_invalid_allocations_are_rejected(): void
    {
        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'POS-ALLOC-2',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 30000],
            ],
            'subtotal' => 30000,
            'tax' => 0,
            'total' => 30000,
            'payments' => [],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $pay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 30000,
                    'allocations' => [
                        ['orderItemId' => $orderItemId, 'qty' => -1, 'amount' => 30000],
                    ],
                ],
            ],
        ]);

        $pay->assertStatus(422);
    }

    public function test_over_allocation_is_rejected(): void
    {
        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'POS-ALLOC-3',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 2, 'price' => 15000],
            ],
            'subtotal' => 30000,
            'tax' => 0,
            'total' => 30000,
            'payments' => [],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $first = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 15000,
                    'allocations' => [
                        ['orderItemId' => $orderItemId, 'qty' => 1.5, 'amount' => 15000],
                    ],
                ],
            ],
        ]);
        $first->assertOk();

        $second = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                [
                    'method' => 'qris',
                    'amount' => 15000,
                    'allocations' => [
                        ['orderItemId' => $orderItemId, 'qty' => 0.6, 'amount' => 15000],
                    ],
                ],
            ],
        ]);
        $second->assertStatus(422);
    }

    public function test_status_endpoint_cannot_force_payment_status_paid(): void
    {
        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => 1,
            'code' => 'POS-STATUS-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'pending',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 20000],
            ],
            'subtotal' => 20000,
            'tax' => 0,
            'total' => 20000,
            'payments' => [],
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');

        $update = $this->patchJson("/api/v1/orders/{$orderId}/status", [
            'status' => 'confirmed',
            'paymentStatus' => 'paid',
        ]);
        $update->assertStatus(422);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => 'unpaid',
        ]);
    }
}
