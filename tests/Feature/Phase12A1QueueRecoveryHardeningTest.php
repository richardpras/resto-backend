<?php

namespace Tests\Feature;

use App\Jobs\Payments\ReconcilePaymentTransactionJob;
use App\Jobs\Payments\RecoverStalePaymentsJob;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Payments\Services\PaymentGatewayService;
use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase12A1QueueRecoveryHardeningTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['queue.default' => 'sync']);
        config(['payments.providers.manual.webhook_secret' => 'phase12a1-secret']);
        config(['payments.providers.manual.class' => Phase12A1ManualProvider::class]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_duplicate_webhook_replay_is_durable_and_idempotent(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P12A1-WH');
        $this->seedPostingAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P12A1-WH-ORDER');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p12a1-wh-ext-1', 'p12a1-wh-idem-1');

        $payload = ['externalReference' => 'p12a1-wh-ext-1', 'status' => 'paid', 'eventId' => 'p12a1-wh-evt-1'];
        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionId, 'status' => 'paid']);
        $this->assertEquals(1, DB::table('payment_webhook_receipts')->where('provider', 'manual')->where('event_idempotency_key', 'manual#p12a1-wh-evt-1')->count());
        $this->assertEquals(1, DB::table('journals')->where('source_type', 'payment_transaction')->where('source_id', (string) $transactionId)->count());
    }

    public function test_stale_pending_recovery_and_async_retry_flow_are_safe(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P12A1-REC');
        $this->seedPostingAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P12A1-REC-ORDER');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p12a1-rec-ext-1', 'p12a1-rec-idem-1');

        Artisan::call('payments:recover-stuck', ['--limit' => 20]);
        Artisan::call('payments:retry-async-failures', ['--limit' => 20]);
        Artisan::call('payments:recover-stuck', ['--limit' => 20]);

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionId, 'status' => 'paid']);
        $this->assertEquals(1, DB::table('journals')->where('source_type', 'payment_transaction')->where('source_id', (string) $transactionId)->count());
        $this->assertNotNull(PaymentTransaction::query()->findOrFail($transactionId)->last_reconciled_at);
    }

    public function test_delayed_reconciliation_tolerance_persists_receipt_for_retry(): void
    {
        $payload = ['externalReference' => 'p12a1-missing-ext-1', 'status' => 'paid', 'eventId' => 'p12a1-missing-evt-1'];
        $this->postSignedWebhook($payload)->assertNotFound();

        $this->assertDatabaseHas('payment_webhook_receipts', [
            'provider' => 'manual',
            'event_idempotency_key' => 'manual#p12a1-missing-evt-1',
            'external_reference' => 'p12a1-missing-ext-1',
        ]);
        $this->assertDatabaseHas('payment_webhook_receipts', [
            'provider' => 'manual',
            'event_idempotency_key' => 'manual#p12a1-missing-evt-1',
        ]);
    }

    public function test_recovery_commands_keep_outlet_isolation(): void
    {
        [$userA, $outletA] = $this->actAsAdminWithOutlet('P12A1-OA');
        $outletB = Outlet::query()->create([
            'name' => 'P12A1-OB Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p12a1-ob-'.uniqid(),
        ]);
        $this->assignUserToOutlets($userA, [(int) $outletA->id, (int) $outletB->id]);
        $this->seedPostingAccounts((int) $outletA->id);
        $this->seedPostingAccounts((int) $outletB->id);

        $orderA = $this->createConfirmedOrder((int) $outletA->id, (int) $userA->id, 'P12A1-OA-ORDER');
        $orderB = $this->createConfirmedOrder((int) $outletB->id, (int) $userA->id, 'P12A1-OB-ORDER');
        $transactionA = $this->createPaymentTransaction($orderA, (int) $outletA->id, 'p12a1-oa-ext-1', 'p12a1-oa-idem-1');
        $transactionB = $this->createPaymentTransaction($orderB, (int) $outletB->id, 'p12a1-ob-ext-1', 'p12a1-ob-idem-1');

        Artisan::call('payments:recover-stuck', ['--limit' => 50]);

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionA, 'status' => 'paid', 'outlet_id' => $outletA->id]);
        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionB, 'status' => 'paid', 'outlet_id' => $outletB->id]);
        $this->assertEquals(1, DB::table('journals')->where('source_type', 'payment_transaction')->where('source_id', (string) $transactionA)->where('outlet_id', $outletA->id)->count());
        $this->assertEquals(1, DB::table('journals')->where('source_type', 'payment_transaction')->where('source_id', (string) $transactionB)->where('outlet_id', $outletB->id)->count());
    }

    public function test_reconcile_command_dispatches_recovery_job_with_observability_context(): void
    {
        Queue::fake();

        Artisan::call('payments:reconcile-stale', ['--limit' => 7]);

        Queue::assertPushed(RecoverStalePaymentsJob::class, function (RecoverStalePaymentsJob $job): bool {
            return $job->limit === 7
                && ($job->observabilityContext['operation'] ?? null) === 'payments.reconcile_stale'
                && ($job->observabilityContext['command'] ?? null) === 'payments:reconcile-stale'
                && is_string($job->observabilityContext['correlation_id'] ?? null)
                && trim((string) ($job->observabilityContext['correlation_id'] ?? '')) !== ''
                && is_string($job->observabilityContext['trace_id'] ?? null)
                && trim((string) ($job->observabilityContext['trace_id'] ?? '')) !== '';
        });
    }

    public function test_reconcile_dispatch_propagates_identifier_context_into_reconcile_jobs(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('P12A1-OBS');
        $this->seedPostingAccounts((int) $outlet->id);
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P12A1-OBS-ORDER');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p12a1-obs-ext-1', 'p12a1-obs-idem-1');

        Queue::fake();
        app(PaymentGatewayService::class)->dispatchPendingReconciliation(20, [
            'correlation_id' => 'test-correlation-id',
            'trace_id' => 'test-trace-id',
        ]);

        Queue::assertPushed(ReconcilePaymentTransactionJob::class, function (ReconcilePaymentTransactionJob $job) use ($transactionId, $outlet): bool {
            return $job->transactionId === $transactionId
                && ($job->observabilityContext['correlation_id'] ?? null) === 'test-correlation-id'
                && ($job->observabilityContext['trace_id'] ?? null) === 'test-trace-id'
                && ($job->observabilityContext['transaction_id'] ?? null) === $transactionId
                && ($job->observabilityContext['outlet_id'] ?? null) === (int) $outlet->id
                && ($job->observabilityContext['provider'] ?? null) === 'manual'
                && ($job->observabilityContext['external_reference'] ?? null) === 'p12a1-obs-ext-1';
        });
    }

    private function postSignedWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $raw, (string) config('payments.providers.manual.webhook_secret'));

        return $this->withHeaders(['X-Signature' => $signature])
            ->postJson('/api/v1/payment-webhooks/manual', $payload);
    }

    /** @return array{0:User,1:Outlet} */
    private function actAsAdminWithOutlet(string $prefix): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    private function seedPostingAccounts(int $outletId): void
    {
        $cashCode = '11'.str_pad((string) $outletId, 2, '0', STR_PAD_LEFT);
        $salesCode = '41'.str_pad((string) $outletId, 2, '0', STR_PAD_LEFT);
        DB::table('accounts')->insert([
            [
                'tenant_id' => 1,
                'outlet_id' => $outletId,
                'scope' => 'outlet',
                'code' => $cashCode,
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
                'code' => $salesCode,
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
            'name' => 'P12A1-T-'.uniqid(),
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
                ['id' => '1201', 'name' => 'P12 Item', 'qty' => 1, 'price' => 12000],
            ],
            'subtotal' => 12000,
            'tax' => 0,
            'total' => 12000,
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
            'amount' => 12000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }
}

class Phase12A1ManualProvider implements PaymentProviderInterface
{
    public function createTransaction(array $payload): array
    {
        return [
            'externalReference' => (string) $payload['externalReference'],
            'paymentMethod' => 'qris',
            'status' => 'pending',
            'checkout_url' => 'https://manual.local/'.$payload['externalReference'],
            'qr_string' => 'P12A1-'.$payload['externalReference'],
            'deeplink_url' => null,
            'expiry_time' => now()->addMinutes(30)->toISOString(),
            'provider_metadata' => ['provider' => 'phase12a1-manual'],
            'raw' => ['provider' => 'phase12a1-manual'],
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
