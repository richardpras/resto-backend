<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

/**
 * Phase 11 — Backend Integrity Subagent A.
 *
 * Additive financial integrity validation for the existing POS ecosystem.
 * Verifies double-entry balance, idempotency, allocation reconciliation,
 * and cash variance posting correctness without introducing new features.
 */
class Phase11FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_journal_totals_remain_balanced_under_idempotent_retries(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P11FI Balanced');
        [$cashId, $salesId] = $this->seedBasicAccounts((int) $outlet->id);

        $payload = [
            'journalDate' => now()->format('Y-m-d'),
            'description' => 'P11FI balanced retry',
            'status' => 'posted',
            'outletId' => (int) $outlet->id,
            'postingKey' => 'p11fi-balance-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 73250, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 73250],
            ],
        ];

        $first = $this->postJson('/api/v1/journals', $payload);
        $first->assertCreated();
        $journalId = (int) $first->json('data.id');

        for ($i = 0; $i < 3; $i++) {
            $retry = $this->postJson('/api/v1/journals', $payload);
            $retry->assertCreated()->assertJsonPath('data.id', (string) $journalId);
        }

        $sumDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $sumCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame(round($sumDebit, 2), round($sumCredit, 2), 'Posted journal must remain balanced under retry.');
        $this->assertSame(
            1,
            DB::table('journals')->where('source_id', $journalId)->orWhere('id', $journalId)->where('id', $journalId)->count(),
            'Idempotent retries must not produce duplicate journals.'
        );
    }

    public function test_unbalanced_journal_payload_is_rejected_and_does_not_persist(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('P11FI Unbalanced');
        [$cashId, $salesId] = $this->seedBasicAccounts((int) $outlet->id);

        $resp = $this->postJson('/api/v1/journals', [
            'journalDate' => now()->format('Y-m-d'),
            'description' => 'P11FI unbalanced',
            'status' => 'posted',
            'outletId' => (int) $outlet->id,
            'postingKey' => 'p11fi-unbalanced-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 1000, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 999.5],
            ],
        ]);

        $resp->assertUnprocessable();
        $this->assertSame(
            0,
            DB::table('journals')->where('outlet_id', (int) $outlet->id)->count(),
            'Unbalanced journal payload must not create a journal row.'
        );
        $this->assertSame(
            0,
            DB::table('journal_entries')->count(),
            'Unbalanced journal payload must not create journal entries.'
        );
    }

    public function test_payment_completion_amounts_match_paid_total_and_journal_lines(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11FI Match');
        [$cashId, $salesId, $cogsId, $inventoryId] = $this->seedFullAccounts((int) $outlet->id);
        [$ingredient, $menuItem] = $this->seedRecipeContext((int) $outlet->id);

        $orderId = $this->createConfirmedOrder(
            (int) $outlet->id,
            (int) $user->id,
            'P11FI-MATCH-1',
            (int) $menuItem->id,
            qty: 4,
            unitPrice: 75
        );

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 100],
                ['method' => 'transfer', 'amount' => 200],
            ],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $orderTotal = (float) DB::table('orders')->where('id', $orderId)->value('total');
        $paidTotal = (float) DB::table('orders')->where('id', $orderId)->value('paid_total');
        $balanceDue = (float) DB::table('orders')->where('id', $orderId)->value('balance_due');
        $sumPayments = (float) DB::table('payments')->where('order_id', $orderId)->sum('amount');

        $this->assertSame(round($orderTotal, 2), round($paidTotal, 2), 'paid_total must equal order total when fully paid.');
        $this->assertSame(round($paidTotal, 2), round($sumPayments, 2), 'Sum of payment rows must equal paid_total.');
        $this->assertSame(0.0, round($balanceDue, 2), 'balance_due must be zero on full payment.');

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->value('id');
        $this->assertGreaterThan(0, $journalId, 'order_payment journal must be posted on completion.');

        $sumDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $sumCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame(round($sumDebit, 2), round($sumCredit, 2), 'order_payment journal must remain balanced (debit==credit).');
        $this->assertGreaterThanOrEqual($paidTotal, $sumDebit + 0.0001);

        $cashDebit = (float) DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->where('account_id', $cashId)
            ->value('debit');
        $salesCredit = (float) DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->where('account_id', $salesId)
            ->value('credit');
        $this->assertSame(round($paidTotal, 2), round($cashDebit, 2), 'Cash debit must equal paid_total.');
        $this->assertSame(round($paidTotal, 2), round($salesCredit, 2), 'Sales credit must equal paid_total.');

        $cogsDebit = (float) DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->where('account_id', $cogsId)
            ->value('debit');
        $inventoryCredit = (float) DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->where('account_id', $inventoryId)
            ->value('credit');
        $this->assertSame(round($cogsDebit, 2), round($inventoryCredit, 2), 'COGS debit must equal Inventory credit.');
        $this->assertGreaterThan(0.0, $cogsDebit, 'COGS line must be posted when recipe is configured.');
    }

    public function test_posting_for_order_payment_is_idempotent_and_does_not_create_duplicate_cogs(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11FI No-Dup');
        $this->seedFullAccounts((int) $outlet->id);
        [, $menuItem] = $this->seedRecipeContext((int) $outlet->id);

        $orderId = $this->createConfirmedOrder(
            (int) $outlet->id,
            (int) $user->id,
            'P11FI-DUP-1',
            (int) $menuItem->id,
            qty: 2,
            unitPrice: 50
        );

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 100]],
        ])->assertOk();

        $firstCount = DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->count();
        $this->assertSame(1, $firstCount, 'Initial completion must produce exactly one order_payment journal.');

        for ($i = 0; $i < 2; $i++) {
            app(JournalPostingService::class)->postForOrderPayment(
                (int) $orderId,
                tenantId: 1,
                outletId: (int) $outlet->id,
                sales: 100.0,
                cogs: (float) DB::table('stock_movements')
                    ->where('source_type', 'order_payment')
                    ->where('source_id', 'P11FI-DUP-1')
                    ->sum('total_cost')
            );
        }

        $finalCount = DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->count();
        $this->assertSame(1, $finalCount, 'Repeat postings must be deduplicated by posting_key (no duplicate COGS).');

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->value('id');
        $cogsRows = DB::table('journal_entries')
            ->where('journal_id', $journalId)
            ->where('memo', 'COGS recognition')
            ->count();
        $this->assertLessThanOrEqual(1, $cogsRows, 'COGS line must not be duplicated within the same journal.');
    }

    public function test_split_allocation_payments_reconcile_exactly_to_order_total(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11FI Split-Recon');
        $this->seedBasicAccounts((int) $outlet->id);
        [, $menuItem] = $this->seedRecipeContext((int) $outlet->id);

        $orderId = $this->createConfirmedOrder(
            (int) $outlet->id,
            (int) $user->id,
            'P11FI-SPLIT-1',
            (int) $menuItem->id,
            qty: 3,
            unitPrice: 40
        );
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $splitA = $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Guest A',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 2,
                'amount' => 80,
            ]],
        ]);
        $splitA->assertCreated();
        $splitAId = (int) $splitA->json('data.id');

        $splitB = $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Guest B',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 1,
                'amount' => 40,
            ]],
        ]);
        $splitB->assertCreated();
        $splitBId = (int) $splitB->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'cash',
                'amount' => 80,
                'orderSplitId' => $splitAId,
                'allocations' => [[
                    'orderItemId' => $orderItemId,
                    'qty' => 2,
                    'amount' => 80,
                ]],
            ]],
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [[
                'method' => 'transfer',
                'amount' => 40,
                'orderSplitId' => $splitBId,
                'allocations' => [[
                    'orderItemId' => $orderItemId,
                    'qty' => 1,
                    'amount' => 40,
                ]],
            ]],
        ])->assertOk();

        $orderTotal = (float) DB::table('orders')->where('id', $orderId)->value('total');
        $sumPayments = (float) DB::table('payments')->where('order_id', $orderId)->sum('amount');
        $sumAllocAmount = (float) DB::table('order_payment_allocations')
            ->whereIn('payment_id', DB::table('payments')->where('order_id', $orderId)->pluck('id'))
            ->sum('amount');
        $sumAllocQty = (float) DB::table('order_payment_allocations')
            ->whereIn('payment_id', DB::table('payments')->where('order_id', $orderId)->pluck('id'))
            ->sum('qty');
        $orderItemQty = (float) DB::table('order_items')->where('id', $orderItemId)->value('qty');

        $this->assertSame(round($orderTotal, 2), round($sumPayments, 2), 'Sum of payments must equal order total.');
        $this->assertSame(round($sumPayments, 2), round($sumAllocAmount, 2), 'Sum of payment allocation amounts must match payments.');
        $this->assertSame(round($orderItemQty, 2), round($sumAllocQty, 2), 'Sum of allocation qty must equal order_item qty.');

        $finalPaymentStatus = (string) DB::table('orders')->where('id', $orderId)->value('payment_status');
        $this->assertSame('paid', $finalPaymentStatus, 'Order must be marked paid once all splits are reconciled.');
    }

    public function test_overpayment_via_split_allocations_is_rejected_and_journal_not_double_posted(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11FI Overpay');
        $this->seedBasicAccounts((int) $outlet->id);
        [, $menuItem] = $this->seedRecipeContext((int) $outlet->id);

        $orderId = $this->createConfirmedOrder(
            (int) $outlet->id,
            (int) $user->id,
            'P11FI-OVER-1',
            (int) $menuItem->id,
            qty: 1,
            unitPrice: 50
        );

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 50]],
        ])->assertOk();

        $extra = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 25]],
        ]);
        $extra->assertUnprocessable();

        $this->assertSame(
            1,
            DB::table('journals')
                ->where('source_type', 'order_payment')
                ->where('source_id', (string) $orderId)
                ->count(),
            'Rejected overpayment must not produce a duplicate order_payment journal.'
        );
        $sumPayments = (float) DB::table('payments')->where('order_id', $orderId)->sum('amount');
        $orderTotal = (float) DB::table('orders')->where('id', $orderId)->value('total');
        $this->assertSame(round($orderTotal, 2), round($sumPayments, 2), 'Payment ledger must remain reconciled after overpayment rejection.');
    }

    public function test_pos_session_close_posts_cash_variance_journal_with_balanced_sides(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11FI Variance');
        $this->seedBasicAccounts((int) $outlet->id);
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'scope' => 'outlet',
            'category' => 'cash_variance',
            'code' => '5400',
            'name' => 'Cash Over/Short',
            'type' => 'expense',
            'subtype' => 'operational_expense',
            'is_active' => true,
        ]);

        $open = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => (int) $outlet->id,
            'openingCash' => 100000,
            'notes' => 'P11FI variance open',
        ]);
        $open->assertCreated();
        $sessionId = (int) $open->json('data.id');

        $close = $this->postJson("/api/v1/pos-sessions/{$sessionId}/close", [
            'closingCash' => 95500,
            'notes' => 'P11FI variance close (short -4500)',
        ]);
        $close->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.cashVariance', -4500);

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'pos_cash_variance')
            ->where('source_id', (string) $sessionId)
            ->value('id');
        $this->assertGreaterThan(0, $journalId, 'POS cash variance journal must be posted on session close.');

        $sumDebit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('debit');
        $sumCredit = (float) DB::table('journal_entries')->where('journal_id', $journalId)->sum('credit');
        $this->assertSame(round($sumDebit, 2), round($sumCredit, 2), 'Cash variance journal must be balanced.');
        $this->assertSame(4500.0, round($sumDebit, 2), 'Cash variance journal must equal absolute variance.');

        $varianceRow = DB::table('pos_sessions')->where('id', $sessionId)->value('cash_variance');
        $this->assertSame(-4500.0, (float) $varianceRow, 'POS session must record signed variance.');
    }

    public function test_zero_cash_variance_does_not_post_journal(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P11FI Zero-Var');
        $this->seedBasicAccounts((int) $outlet->id);
        Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'scope' => 'outlet',
            'category' => 'cash_variance',
            'code' => '5400',
            'name' => 'Cash Over/Short',
            'type' => 'expense',
            'subtype' => 'operational_expense',
            'is_active' => true,
        ]);

        $open = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => (int) $outlet->id,
            'openingCash' => 50000,
        ]);
        $open->assertCreated();
        $sessionId = (int) $open->json('data.id');

        $this->postJson("/api/v1/pos-sessions/{$sessionId}/close", [
            'closingCash' => 50000,
        ])->assertOk()->assertJsonPath('data.cashVariance', 0);

        $this->assertSame(
            0,
            DB::table('journals')
                ->where('source_type', 'pos_cash_variance')
                ->where('source_id', (string) $sessionId)
                ->count(),
            'Zero variance must not post a journal.'
        );
    }

    /** @return array{0: User, 1: Outlet} */
    private function actAsAdminWithOutlet(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11fi-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    /** @return array{0:int,1:int} */
    private function seedBasicAccounts(int $outletId): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'cash_bank',
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'sales_revenue',
            'code' => '4100',
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function seedFullAccounts(int $outletId): array
    {
        [$cashId, $salesId] = $this->seedBasicAccounts($outletId);
        $cogs = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'cogs',
            'code' => '5100',
            'name' => 'COGS',
            'type' => 'expense',
            'subtype' => 'cogs',
            'is_active' => true,
        ]);
        $inventory = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'category' => 'inventory',
            'code' => '1300',
            'name' => 'Inventory',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);

        return [$cashId, $salesId, (int) $cogs->id, (int) $inventory->id];
    }

    /** @return array{0:Ingredient,1:MenuItem} */
    private function seedRecipeContext(int $outletId): array
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11FI Ingredient '.uniqid(),
            'type' => 'ingredient',
            'unit' => 'gram',
            'stock' => 0,
            'min' => 0,
            'price' => 1.5,
        ]);
        DB::table('inventory_stocks')->insert([
            'ingredient_id' => (int) $ingredient->id,
            'outlet_id' => $outletId,
            'stock' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P11FI Menu '.uniqid(),
            'category' => 'main',
            'price' => 75,
            'available' => true,
        ]);
        DB::table('menu_recipes')->insert([
            'menu_item_id' => (int) $menuItem->id,
            'inventory_item_id' => (int) $ingredient->id,
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$ingredient, $menuItem];
    }

    private function createConfirmedOrder(int $outletId, int $userId, string $code, int $menuItemId, int $qty, float $unitPrice): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P11FI-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $userId,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
        $total = $qty * $unitPrice;

        $resp = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => (string) $menuItemId, 'name' => 'P11FI Menu', 'qty' => $qty, 'price' => $unitPrice],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]);
        $resp->assertCreated();

        return (int) $resp->json('data.id');
    }
}
