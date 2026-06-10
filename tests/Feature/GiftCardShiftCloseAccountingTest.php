<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class GiftCardShiftCloseAccountingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'gc-shift-close-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_shift_close_cash_and_gift_card_posts_single_shift_close_journal(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('GC Shift Close');
        $this->setRevenuePostingMode(AccountingSetting::MODE_SHIFT_CLOSE, (int) $outlet->id);
        $this->seedFullAccountingAccounts();

        $orderId = $this->createPaidCashOrder($outlet->id, (int) $user->id, 'GC-SC-70-30', 100000, 70000);
        $settlementId = $this->issueRedeemAndSettle($outlet->id, $orderId, 'GC-SC-001', 30000);

        $this->assertEquals(0, DB::table('journals')->where('source_type', 'gift_card_settlement')->count());

        $result = app(OrderService::class)->closeShiftAndPostJournal(1, (int) $outlet->id);
        $journalId = (int) ($result['journalId'] ?? 0);
        $this->assertGreaterThan(0, $journalId);
        $this->assertEquals(1, DB::table('journals')->where('source_type', 'shift_close')->count());

        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(70000, $lines['1100']['debit'] ?? 0);
        $this->assertEquals(30000, $lines['2130']['debit'] ?? 0);
        $this->assertEquals(100000, $lines['4100']['credit'] ?? 0);
        $this->assertEquals(0, DB::table('journals')->where('source_type', 'gift_card_settlement')->count());

        $this->assertDatabaseHas('gift_card_redemption_settlements', [
            'id' => $settlementId,
            'status' => 'settled',
        ]);
    }

    public function test_shift_close_hundred_percent_gift_card_posts_shift_close_only(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('GC Shift Close Full');
        $this->setRevenuePostingMode(AccountingSetting::MODE_SHIFT_CLOSE, (int) $outlet->id);
        $this->seedFullAccountingAccounts();

        $orderId = $this->createPaidCashOrder($outlet->id, (int) $user->id, 'GC-SC-FULL', 100000, 0);
        $this->issueRedeemAndSettle($outlet->id, $orderId, 'GC-SC-FULL', 100000);

        $this->assertEquals(0, DB::table('journals')->where('source_type', 'gift_card_settlement')->count());

        $result = app(OrderService::class)->closeShiftAndPostJournal(1, (int) $outlet->id);
        $journalId = (int) ($result['journalId'] ?? 0);
        $this->assertGreaterThan(0, $journalId);

        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(100000, $lines['2130']['debit'] ?? 0);
        $this->assertEquals(100000, $lines['4100']['credit'] ?? 0);
        $this->assertEquals(0, $lines['1100']['debit'] ?? 0);
    }

    public function test_realtime_cash_and_gift_card_uses_order_payment_and_settlement_journals(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('GC Realtime');
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);
        $this->seedFullAccountingAccounts();

        $orderId = $this->createPaidOrderWithCashJournal((int) $outlet->id, 'GC-RT-70-30', 70000, 70000);
        $settlementId = $this->issueRedeemAndSettle($outlet->id, $orderId, 'GC-RT-001', 30000);

        $orderPaymentJournalId = (int) DB::table('journals')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $orderId)
            ->value('id');
        $settlementJournalId = (int) DB::table('journals')
            ->where('source_type', 'gift_card_settlement')
            ->where('source_id', (string) $settlementId)
            ->value('id');

        $this->assertGreaterThan(0, $orderPaymentJournalId);
        $this->assertGreaterThan(0, $settlementJournalId);

        $orderLines = $this->journalLinesByCode($orderPaymentJournalId);
        $settleLines = $this->journalLinesByCode($settlementJournalId);

        $this->assertEquals(70000, $orderLines['1100']['debit'] ?? 0);
        $this->assertEquals(70000, $orderLines['4100']['credit'] ?? 0);
        $this->assertEquals(30000, $settleLines['2130']['debit'] ?? 0);
        $this->assertEquals(30000, $settleLines['4100']['credit'] ?? 0);

        $totalRevenue = ($orderLines['4100']['credit'] ?? 0) + ($settleLines['4100']['credit'] ?? 0);
        $this->assertEquals(100000, $totalRevenue);
    }

    public function test_gateway_gift_card_split_has_no_supplemental_settlement_journal(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('GC Gateway');
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);
        $this->seedFullAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'GC-GW-70-30', 100000);

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-GW-001',
            'initialAmount' => 50000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-gw-1',
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-GW-001',
            'amount' => 30000,
            'idempotencyKey' => 'redeem-gc-gw-1',
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');
        $this->enableGatewayQrisForOutlet((int) $outlet->id);

        $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => (int) $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-gc-gw-reg',
            'idempotencyKey' => 'idem-gc-gw-reg',
            'amount' => 70000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
            'payloadSnapshot' => ['giftCardSettlementIds' => [$settlementId]],
        ])->assertCreated();

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-gc-gw-reg',
            'status' => 'paid',
            'eventId' => 'evt-gc-gw-reg',
        ])->assertOk();

        $this->assertEquals(1, DB::table('journals')->where('source_type', 'payment_transaction')->count());
        $this->assertEquals(0, DB::table('journals')->where('source_type', 'gift_card_settlement')->count());

        $journalId = (int) DB::table('journals')->where('source_type', 'payment_transaction')->value('id');
        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(70000, $lines['1100']['debit'] ?? 0);
        $this->assertEquals(30000, $lines['2130']['debit'] ?? 0);
        $this->assertEquals(100000, $lines['4100']['credit'] ?? 0);
    }

    private function issueRedeemAndSettle(int $outletId, int $orderId, string $code, int $amount): int
    {
        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => $outletId,
            'instrumentType' => 'gift_card',
            'code' => $code,
            'initialAmount' => max($amount, 50000),
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-'.$code,
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => $outletId,
            'code' => $code,
            'amount' => $amount,
            'idempotencyKey' => 'redeem-'.$code,
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');

        $this->postJson('/api/v1/gift-cards/settlements', [
            'outletId' => $outletId,
            'idempotencyKey' => 'settle-'.$code,
            'settlementReference' => 'pos-order#'.$orderId,
            'settlementStatus' => 'settled',
            'redeemSettlementIds' => [$settlementId],
        ])->assertOk();

        return $settlementId;
    }

    private function createPaidOrderWithCashJournal(int $outletId, string $code, int $total, int $paidTotal): int
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'paid_total' => $paidTotal,
            'balance_due' => max(0, $total - $paidTotal),
            'is_posted' => false,
        ]);

        app(JournalPostingService::class)->postForOrderPayment(
            (int) $order->id,
            1,
            $outletId,
            (float) $paidTotal,
            0.0,
        );

        return (int) $order->id;
    }

    private function createPaidCashOrder(int $outletId, int $openedByUserId, string $code, int $total, int $paidTotal): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'GC-SC-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $openedByUserId,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'paid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => '301', 'name' => 'Item A', 'qty' => 1, 'price' => $total],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => $paidTotal > 0 ? [[
                'method' => 'cash',
                'amount' => $paidTotal,
                'paidAt' => now()->toIso8601String(),
            ]] : [],
        ]);
        $create->assertCreated();

        $orderId = (int) $create->json('data.id');
        Order::query()->where('id', $orderId)->update([
            'paid_total' => $paidTotal,
            'payment_status' => 'paid',
            'is_posted' => false,
        ]);

        return $orderId;
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code, int $total): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'GC-GW-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $openedByUserId,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
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
                ['id' => '302', 'name' => 'Item A', 'qty' => 1, 'price' => $total],
            ],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function journalLinesByCode(int $journalId): array
    {
        $rows = DB::table('journal_entries as je')
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->where('je.journal_id', $journalId)
            ->get(['a.code', 'je.debit', 'je.credit']);

        $lines = [];
        foreach ($rows as $row) {
            $lines[(string) $row->code] = [
                'debit' => (float) $row->debit,
                'credit' => (float) $row->credit,
            ];
        }

        return $lines;
    }

    private function seedFullAccountingAccounts(): void
    {
        foreach ([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'category' => 'cash_bank'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'category' => 'inventory'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'category' => 'gift_card_liability'],
            ['code' => '4100', 'name' => 'Sales', 'type' => 'revenue', 'category' => 'sales_revenue'],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'category' => 'cogs'],
        ] as $row) {
            if (DB::table('accounts')->where('code', $row['code'])->exists()) {
                continue;
            }
            DB::table('accounts')->insert([
                'tenant_id' => 1,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'category' => $row['category'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function enableGatewayQrisForOutlet(int $outletId): void
    {
        DB::table('outlet_payment_method_configs')->upsert([
            [
                'outlet_id' => $outletId,
                'payment_method_code' => 'gateway_qris',
                'type' => 'gateway_qris',
                'provider' => 'manual',
                'enabled' => true,
                'display_order' => 10,
                'is_default' => true,
                'settings' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['outlet_id', 'payment_method_code'], ['enabled', 'provider', 'updated_at']);
    }

    private function postSignedWebhook(string $provider, array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $raw, (string) config('payments.providers.'.$provider.'.webhook_secret'));

        return $this->withHeaders(['X-Signature' => $signature])
            ->postJson('/api/v1/payment-webhooks/'.$provider, $payload);
    }
}
