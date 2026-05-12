<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use App\Modules\Payments\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase10PaymentGatewayFoundationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'phase10-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_duplicate_webhook_is_ignored_idempotently(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-DUP-WEBHOOK');

        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-dup-1', 'idem-p10-dup-1');

        $payload = [
            'externalReference' => 'ext-p10-dup-1',
            'status' => 'paid',
            'eventId' => 'evt-dup-1',
            'paymentMethod' => 'qris',
            'payload' => ['gatewayStatus' => 'success'],
        ];
        $this->postSignedWebhook('manual', $payload)->assertOk();
        $this->postSignedWebhook('manual', $payload)->assertOk();

        $this->assertEquals(
            1,
            DB::table('payment_transaction_events')
                ->where('payment_transaction_id', $transactionId)
                ->where('event_type', 'status_changed')
                ->where('payload->to', 'paid')
                ->count()
        );
        $this->assertSame(
            1,
            DB::table('payment_webhook_receipts')
                ->where('provider', 'manual')
                ->where('event_idempotency_key', 'manual#evt-dup-1')
                ->count()
        );
        $this->assertNotNull(
            DB::table('payment_webhook_receipts')
                ->where('provider', 'manual')
                ->where('event_idempotency_key', 'manual#evt-dup-1')
                ->value('processed_at')
        );
    }

    public function test_stale_status_is_rejected(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-STALE');
        $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-stale-1', 'idem-p10-stale-1');

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-stale-1',
            'status' => 'paid',
            'eventId' => 'evt-stale-paid',
        ])->assertOk();

        $stale = $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-stale-1',
            'status' => 'pending',
            'eventId' => 'evt-stale-pending',
        ]);
        $stale->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payment_transaction_events', [
            'event_type' => 'duplicate_ignored',
        ]);
    }

    public function test_split_transaction_flow_is_supported(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-SPLIT');
        $splitId = (int) DB::table('order_splits')->insertGetId([
            'order_id' => $orderId,
            'split_type' => 'by_item',
            'label' => 'Split A',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'orderSplitId' => $splitId,
            'outletId' => $outlet->id,
            'provider' => 'manual',
            'externalReference' => 'ext-p10-split-1',
            'idempotencyKey' => 'idem-p10-split-1',
            'amount' => 5500,
            'currency' => 'IDR',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.orderSplitId', $splitId)
            ->assertJsonStructure([
                'data' => [
                    'checkoutUrl',
                    'qrString',
                    'deeplinkUrl',
                    'expiresAt',
                    'providerMetadataSnapshot',
                ],
            ]);
    }

    public function test_initiate_uses_config_default_provider_when_request_omits_provider(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-NO-PROV');

        Config::set('payments.default_provider', 'manual');

        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => $outlet->id,
            'externalReference' => 'ext-p10-no-prov-1',
            'idempotencyKey' => 'idem-p10-no-prov-1',
            'amount' => 11000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'manual');
    }

    public function test_accounting_posting_is_idempotent_on_paid_retries(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-ACC-IDEMP');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-acc-1', 'idem-p10-acc-1');

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-acc-1',
            'status' => 'paid',
            'eventId' => 'evt-acc-1',
        ])->assertOk();

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-acc-1',
            'status' => 'paid',
            'eventId' => 'evt-acc-2',
        ])->assertOk();

        $this->assertEquals(
            1,
            DB::table('journals')
                ->where('source_type', 'payment_transaction')
                ->where('source_id', (string) $transactionId)
                ->count()
        );
    }

    public function test_illegal_rollback_transition_is_rejected(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedAccountingAccounts();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-RETRY');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-retry-1', 'idem-p10-retry-1');

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-retry-1',
            'status' => 'failed',
            'eventId' => 'evt-retry-failed',
        ])->assertOk();

        $rollback = $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-retry-1',
            'status' => 'paid',
            'eventId' => 'evt-retry-paid',
        ]);
        $rollback->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => $transactionId,
            'event_type' => 'duplicate_ignored',
        ]);
    }

    public function test_expired_transaction_handling_marks_status_and_event(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-EXPIRE');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-expire-1', 'idem-p10-expire-1');

        app(PaymentGatewayService::class)->expireTransaction($transactionId);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => $transactionId,
            'event_type' => 'status_changed',
        ]);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-BAD-SIG');
        $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-bad-sig-1', 'idem-p10-bad-sig-1');

        $payload = [
            'externalReference' => 'ext-p10-bad-sig-1',
            'status' => 'paid',
            'eventId' => 'evt-bad-sig',
        ];

        $this->postJson('/api/v1/payment-webhooks/manual', $payload, ['X-Signature' => 'invalid-signature'])
            ->assertStatus(422);
        $this->assertDatabaseHas('payment_transaction_events', [
            'event_type' => 'signature_rejected',
        ]);
    }

    public function test_stale_event_timestamp_is_rejected(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-STALE-TS');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-stale-ts-1', 'idem-p10-stale-ts-1');

        $this->postSignedWebhook('manual', [
            'externalReference' => 'ext-p10-stale-ts-1',
            'status' => 'paid',
            'eventId' => 'evt-stale-ts-1',
            'occurredAt' => now()->subHour()->toISOString(),
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'status' => 'pending',
        ]);
    }

    public function test_reconciliation_stale_recovery_and_provider_retry_are_idempotent(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedAccountingAccounts();
        config(['payments.providers.manual.class' => Phase10ManualPaidProvider::class]);
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-RECON-IDEMP');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-recon-1', 'idem-p10-recon-1');

        Artisan::call('payments:reconcile-stale', ['--limit' => 50]);
        Artisan::call('payments:reconcile-stale', ['--limit' => 50]);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => $transactionId,
            'event_type' => 'reconciliation_run',
        ]);
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => $transactionId,
            'event_type' => 'stale_recovery',
        ]);

        $this->assertEquals(
            1,
            DB::table('journals')
                ->where('source_type', 'payment_transaction')
                ->where('source_id', (string) $transactionId)
                ->count()
        );
    }

    public function test_pending_expiry_automation_marks_expired_once(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'P10-EXPIRE-JOB');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, null, 'ext-p10-expire-job-1', 'idem-p10-expire-job-1');

        DB::table('payment_transactions')
            ->where('id', $transactionId)
            ->update(['expiry_time' => now()->subMinute()]);

        Artisan::call('payments:expire-pending', ['--limit' => 50]);
        Artisan::call('payments:expire-pending', ['--limit' => 50]);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'status' => 'expired',
        ]);
        $this->assertEquals(
            1,
            DB::table('payment_transaction_events')
                ->where('payment_transaction_id', $transactionId)
                ->where('event_type', 'expired')
                ->count()
        );
    }

    private function seedAccountingAccounts(): void
    {
        DB::table('accounts')->insert([
            [
                'tenant_id' => 1,
                'code' => '1100',
                'name' => 'Cash',
                'type' => 'asset',
                'category' => 'cash_bank',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 1,
                'code' => '4100',
                'name' => 'Sales',
                'type' => 'revenue',
                'category' => 'sales_revenue',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createPaymentTransaction(int $orderId, int $outletId, ?int $splitId, string $externalReference, string $idempotencyKey): int
    {
        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'orderSplitId' => $splitId,
            'outletId' => $outletId,
            'provider' => 'manual',
            'externalReference' => $externalReference,
            'idempotencyKey' => $idempotencyKey,
            'amount' => 11000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
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
            'name' => 'P10 Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p10-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P10-T-'.uniqid(),
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

class Phase10ManualPaidProvider implements PaymentProviderInterface
{
    public function createTransaction(array $payload): array
    {
        return [
            'externalReference' => (string) $payload['externalReference'],
            'paymentMethod' => 'qris',
            'status' => 'pending',
            'checkout_url' => 'https://manual.local/'.$payload['externalReference'],
            'qr_string' => 'MANUAL-QR-'.$payload['externalReference'],
            'deeplink_url' => null,
            'expiry_time' => now()->addMinutes(30)->toISOString(),
            'provider_metadata' => ['provider' => 'manual-paid-test'],
            'raw' => ['provider' => 'manual-paid-test'],
        ];
    }

    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool
    {
        return true;
    }

    public function fetchRemoteStatus(string $externalReference): array
    {
        return ['externalReference' => $externalReference, 'status' => 'paid'];
    }

    public function expireOrCancelPayment(string $externalReference): array
    {
        return ['externalReference' => $externalReference, 'status' => 'expired'];
    }

    public function reconcileTransaction(string $externalReference, array $context = []): array
    {
        return ['externalReference' => $externalReference, 'status' => 'paid'];
    }
}
