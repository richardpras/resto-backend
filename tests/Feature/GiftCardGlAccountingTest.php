<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class GiftCardGlAccountingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'gc-gl-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_gift_card_liability_accounts_exist(): void
    {
        $this->seedFullAccountingAccounts();

        $this->assertDatabaseHas('accounts', ['code' => '2130', 'category' => 'gift_card_liability']);
        $this->assertDatabaseHas('accounts', ['code' => '2135', 'category' => 'store_credit_liability']);
        $this->assertDatabaseHas('accounts', ['code' => '4190', 'category' => 'gift_card_breakage']);
    }

    public function test_gateway_payment_posts_full_revenue_split_with_gift_card(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedFullAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'GC-GL-SPLIT', 100000);

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-GL-001',
            'initialAmount' => 50000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-gl-1',
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-GL-001',
            'amount' => 30000,
            'idempotencyKey' => 'redeem-gc-gl-1',
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');
        $this->enableGatewayQrisForOutlet((int) $outlet->id);

        $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => (int) $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-gc-gl-split',
            'idempotencyKey' => 'idem-gc-gl-split',
            'amount' => 70000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
            'payloadSnapshot' => ['giftCardSettlementIds' => [$settlementId]],
        ])->assertCreated();

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-gc-gl-split',
            'status' => 'paid',
            'eventId' => 'evt-gc-gl-split',
        ])->assertOk();

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'payment_transaction')
            ->orderByDesc('id')
            ->value('id');

        $this->assertGreaterThan(0, $journalId);
        $lines = $this->journalLinesByCode($journalId);

        $this->assertEquals(70000, $lines['1100']['debit'] ?? 0);
        $this->assertEquals(30000, $lines['2130']['debit'] ?? 0);
        $this->assertEquals(100000, $lines['4100']['credit'] ?? 0);
        $this->assertEquals(0, DB::table('journals')->where('source_type', 'gift_card_settlement')->count());
    }

    public function test_hundred_percent_gift_card_cash_settle_posts_revenue(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedFullAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'GC-GL-100', 0);

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-GL-FULL',
            'initialAmount' => 100000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-gl-full',
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-GL-FULL',
            'amount' => 100000,
            'idempotencyKey' => 'redeem-gc-gl-full',
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');

        $this->postJson('/api/v1/gift-cards/settlements', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'settle-gc-gl-full',
            'settlementReference' => 'pos-order#'.$orderId,
            'settlementStatus' => 'settled',
            'redeemSettlementIds' => [$settlementId],
        ])->assertOk();

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'gift_card_settlement')
            ->where('source_id', (string) $settlementId)
            ->value('id');

        $this->assertGreaterThan(0, $journalId);
        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(100000, $lines['2130']['debit'] ?? 0);
        $this->assertEquals(100000, $lines['4100']['credit'] ?? 0);
        $this->assertEquals(0, $lines['1100']['debit'] ?? 0);
    }

    public function test_gift_card_reconciliation_endpoint_returns_structure(): void
    {
        $this->actingAsUserManagementApiAdministrator();
        $this->seedFullAccountingAccounts();

        $this->getJson('/api/v1/accounting/reconciliation/gift-cards')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'subledgerOutstanding',
                    'glLiabilityBalance',
                    'giftCardLiabilityOutstanding',
                    'giftCardLiabilityGLBalance',
                    'giftCardLiabilityVariance',
                    'difference',
                    'status',
                    'issuedAmount',
                    'redeemedAmount',
                    'expiredAmount',
                    'pendingSettlements',
                    'settledSettlements',
                ],
            ]);
    }

    public function test_issue_redeem_refund_full_lifecycle(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedFullAccountingAccounts();
        $this->setRevenuePostingMode(\App\Models\Modules\Accounting\Domain\AccountingSetting::MODE_REALTIME, (int) $outlet->id);

        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'GC-LC-FULL', 50000);

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-LC-001',
            'initialAmount' => 50000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-lc-1',
            'cashReceivedAmount' => 50000,
            'paymentMethod' => 'cash',
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-LC-001',
            'amount' => 20000,
            'idempotencyKey' => 'redeem-gc-lc-1',
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');

        \App\Models\Modules\Orders\Domain\Order::query()->where('id', $orderId)->update([
            'paid_total' => 30000,
            'payment_status' => 'paid',
            'total' => 50000,
        ]);
        app(\App\Modules\Accounting\Services\JournalPostingService::class)->postForOrderPayment(
            $orderId,
            1,
            (int) $outlet->id,
            30000.0,
            0.0,
        );

        $this->postJson('/api/v1/gift-cards/settlements', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'settle-gc-lc-1',
            'settlementReference' => 'pos-order#'.$orderId,
            'settlementStatus' => 'settled',
            'redeemSettlementIds' => [$settlementId],
        ])->assertOk();

        app(\App\Modules\Accounting\Services\AccountingRefundPostingService::class)
            ->postRefundForOrder($orderId, 30000, (int) $outlet->id, $user);

        $this->assertEquals(50000, (float) DB::table('gift_card_issuances')->where('code', 'GC-LC-001')->value('balance_amount'));
        $this->assertDatabaseHas('gift_card_redemption_settlements', ['id' => $settlementId, 'status' => 'reversed']);

        $report = app(\App\Modules\GiftCards\Services\GiftCardReconciliationService::class)->report(null, (int) $outlet->id);
        $this->assertEquals(50000, $report['giftCardLiabilityOutstanding']);
        $this->assertEquals(50000, $report['giftCardLiabilityGLBalance']);
    }

    private function setRevenuePostingMode(string $mode, int $outletId): void
    {
        \App\Models\Modules\Accounting\Domain\AccountingSetting::query()->updateOrCreate(
            ['tenant_id' => null, 'outlet_id' => $outletId],
            ['revenue_posting_mode' => $mode],
        );
    }

    public function test_expiry_posts_breakage_journal_idempotently(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedFullAccountingAccounts();

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-GL-EXP',
            'initialAmount' => 25000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-gl-exp',
            'expiresAt' => now()->subDay()->toDateString(),
        ])->assertCreated();

        $this->getJson('/api/v1/gift-cards/GC-GL-EXP?outletId='.(int) $outlet->id)->assertOk();

        $journalCount = DB::table('journals')->where('source_type', 'gift_card_expiry')->count();
        $this->assertEquals(1, $journalCount);

        $journalId = (int) DB::table('journals')->where('source_type', 'gift_card_expiry')->value('id');
        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(25000, $lines['2130']['debit'] ?? 0);
        $this->assertEquals(25000, $lines['4190']['credit'] ?? 0);
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
        $accounts = [
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'category' => 'cash_bank'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'category' => 'inventory'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'category' => 'gift_card_liability'],
            ['code' => '2135', 'name' => 'Store Credit Liability', 'type' => 'liability', 'category' => 'store_credit_liability'],
            ['code' => '4100', 'name' => 'Sales', 'type' => 'revenue', 'category' => 'sales_revenue'],
            ['code' => '4190', 'name' => 'Gift Card Breakage Revenue', 'type' => 'revenue', 'category' => 'gift_card_breakage'],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'category' => 'cogs'],
        ];

        foreach ($accounts as $row) {
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

    private function postSignedWebhook(string $provider, array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $raw, (string) config('payments.providers.'.$provider.'.webhook_secret'));

        return $this->withHeaders(['X-Signature' => $signature])
            ->postJson('/api/v1/payment-webhooks/'.$provider, $payload);
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'GC GL Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'gc-gl-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
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

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code, int $total): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'GC-GL-T-'.uniqid(),
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
                ['id' => '201', 'name' => 'Item A', 'qty' => 1, 'price' => $total > 0 ? $total : 100000],
            ],
            'subtotal' => $total > 0 ? $total : 100000,
            'tax' => 0,
            'total' => $total > 0 ? $total : 100000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }
}
