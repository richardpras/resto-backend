<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase11RecoveryReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'phase11-recovery-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_stale_pending_reconcile_moves_transaction_once_and_is_repeat_safe(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedPostingAccounts((int) $outlet->id);
        config(['payments.providers.manual.class' => Phase11ManualPaidProvider::class]);

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11-RR-STALE-1');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p11-rr-ext-1', 'p11-rr-idem-1');

        Artisan::call('payments:reconcile-stale', ['--limit' => 50]);
        Artisan::call('payments:reconcile-stale', ['--limit' => 50]);

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionId, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_transaction_events', ['payment_transaction_id' => $transactionId, 'event_type' => 'stale_recovery']);
        $this->assertDatabaseHas('payment_transaction_events', ['payment_transaction_id' => $transactionId, 'event_type' => 'reconciliation_run']);
        $this->assertEquals(
            1,
            DB::table('journals')
                ->where('source_type', 'payment_transaction')
                ->where('source_id', (string) $transactionId)
                ->count()
        );
    }

    public function test_webhook_replay_and_expired_transition_are_safely_ignored(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $this->seedPostingAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11-RR-WH-1');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p11-rr-webhook-1', 'p11-rr-webhook-1');

        $paidPayload = [
            'externalReference' => 'p11-rr-webhook-1',
            'status' => 'paid',
            'eventId' => 'p11-rr-evt-1',
        ];
        $this->postSignedWebhook($paidPayload)->assertOk();
        $this->postSignedWebhook($paidPayload)->assertOk();

        $expiredPayload = [
            'externalReference' => 'p11-rr-webhook-1',
            'status' => 'expired',
            'eventId' => 'p11-rr-evt-expired',
        ];
        $this->postSignedWebhook($expiredPayload)->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionId, 'status' => 'paid']);
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => $transactionId,
            'event_type' => 'duplicate_ignored',
        ]);
    }

    public function test_orphaned_transaction_and_partial_failure_paths_are_handled_consistently(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();

        $orphan = $this->postJson('/api/v1/payment-webhooks/manual', [
            'externalReference' => 'p11-orphan-ext-1',
            'status' => 'paid',
            'eventId' => 'p11-orphan-evt-1',
        ], ['X-Signature' => 'invalid']);
        $orphan->assertStatus(422);

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11-RR-PFAIL-1');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p11-rr-pfail-1', 'p11-rr-pfail-1');

        $this->postSignedWebhook([
            'externalReference' => 'p11-rr-pfail-1',
            'status' => 'failed',
            'eventId' => 'p11-rr-pfail-failed',
        ])->assertOk();
        $this->postSignedWebhook([
            'externalReference' => 'p11-rr-pfail-1',
            'status' => 'paid',
            'eventId' => 'p11-rr-pfail-paid',
        ])->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('journals', [
            'source_type' => 'payment_transaction',
            'source_id' => (string) $transactionId,
        ]);
    }

    /** @return array{0:User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'P11 Recovery '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11r-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    private function seedPostingAccounts(int $outletId): void
    {
        DB::table('accounts')->insert([
            [
                'tenant_id' => 1,
                'outlet_id' => $outletId,
                'scope' => 'outlet',
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
                'outlet_id' => $outletId,
                'scope' => 'outlet',
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

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P11-R-T-'.uniqid(),
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
                ['id' => '991', 'name' => 'P11 Recovery Item', 'qty' => 1, 'price' => 11000],
            ],
            'subtotal' => 11000,
            'tax' => 0,
            'total' => 11000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    private function createPaymentTransaction(int $orderId, int $outletId, string $externalReference, string $idempotencyKey): int
    {
        $response = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
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

    private function postSignedWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $raw, (string) config('payments.providers.manual.webhook_secret'));

        return $this->withHeaders(['X-Signature' => $signature])
            ->postJson('/api/v1/payment-webhooks/manual', $payload);
    }
}

class Phase11ManualPaidProvider implements PaymentProviderInterface
{
    public function createTransaction(array $payload): array
    {
        return [
            'externalReference' => (string) $payload['externalReference'],
            'paymentMethod' => 'qris',
            'status' => 'pending',
            'checkout_url' => 'https://manual.local/'.$payload['externalReference'],
            'qr_string' => 'P11-MANUAL-'.$payload['externalReference'],
            'deeplink_url' => null,
            'expiry_time' => now()->addMinutes(30)->toISOString(),
            'provider_metadata' => ['provider' => 'phase11-manual'],
            'raw' => ['provider' => 'phase11-manual'],
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
