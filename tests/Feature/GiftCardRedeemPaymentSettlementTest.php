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

class GiftCardRedeemPaymentSettlementTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'gift-card-redeem-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_gift_card_redeem_payment_success_marks_settlement_settled_via_hook(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'GC-REDEEM-PAY');

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-PAY-001',
            'initialAmount' => 50000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-pay-1',
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-PAY-001',
            'amount' => 11000,
            'idempotencyKey' => 'redeem-gc-pay-1',
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');
        $this->assertGreaterThan(0, $settlementId);
        $this->assertDatabaseHas('gift_card_redemption_settlements', [
            'id' => $settlementId,
            'status' => 'pending',
        ]);

        $this->enableGatewayQrisForOutlet((int) $outlet->id);
        $createPayment = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => (int) $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-gc-pay-1',
            'idempotencyKey' => 'idem-gc-pay-1',
            'amount' => 11000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
            'payloadSnapshot' => [
                'giftCardSettlementIds' => [$settlementId],
            ],
        ]);
        $createPayment->assertCreated();

        $transactionId = (int) $createPayment->json('data.id');
        $snapshot = DB::table('payment_transactions')->where('id', $transactionId)->value('payload_snapshot');
        $decoded = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
        $this->assertIsArray($decoded);
        $this->assertSame([$settlementId], $decoded['giftCardSettlementIds'] ?? null);

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-gc-pay-1',
            'status' => 'paid',
            'eventId' => 'evt-gc-pay-1',
        ])->assertOk();

        $this->assertDatabaseHas('gift_card_redemption_settlements', [
            'id' => $settlementId,
            'status' => 'settled',
            'payment_transaction_id' => $transactionId,
        ]);
    }

    private function seedAccountingAccounts(): void
    {
        foreach ([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'category' => 'cash_bank'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'category' => 'gift_card_liability'],
            ['code' => '4100', 'name' => 'Sales', 'type' => 'revenue', 'category' => 'sales_revenue'],
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
            'name' => 'GC Redeem Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'gc-redeem-'.uniqid(),
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

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'GC-T-'.uniqid(),
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
                ['id' => '201', 'name' => 'Item A', 'qty' => 1, 'price' => 5000],
                ['id' => '202', 'name' => 'Item B', 'qty' => 1, 'price' => 6000],
            ],
            'subtotal' => 11000,
            'tax' => 0,
            'total' => 11000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }
}
