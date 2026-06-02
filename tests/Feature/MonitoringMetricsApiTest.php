<?php

namespace Tests\Feature;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Payments\Domain\PaymentWebhookReceipt;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MonitoringMetricsApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_metrics_endpoint_returns_aggregated_operational_metrics_for_allowed_scope(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outletA = $this->createOutlet('MTA');
        $outletB = $this->createOutlet('MTB');
        $this->assignUserToOutlets($user, [(int) $outletA->id]);

        $sessionA = PosSession::query()->create([
            'outlet_id' => (int) $outletA->id,
            'opened_by_user_id' => (int) $user->id,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);
        PosSession::query()->create([
            'outlet_id' => (int) $outletB->id,
            'opened_by_user_id' => (int) $user->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $orderA = $this->createOrder((int) $outletA->id, (int) $sessionA->id, 'MTA-ORD');
        $orderB = $this->createOrder((int) $outletB->id, null, 'MTB-ORD');
        $tableA = RestaurantTable::query()->create([
            'outlet_id' => (int) $outletA->id,
            'name' => 'T-'.Str::upper(Str::random(6)),
            'capacity' => 4,
            'status' => 'active',
        ]);

        KitchenTicket::query()->create([
            'outlet_id' => (int) $outletA->id,
            'order_id' => $orderA,
            'ticket_no' => 'KT-'.Str::upper(Str::random(8)),
            'status' => 'queued',
            'queued_at' => now(),
        ]);
        KitchenTicket::query()->create([
            'outlet_id' => (int) $outletB->id,
            'order_id' => $orderB,
            'ticket_no' => 'KT-'.Str::upper(Str::random(8)),
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $orderA,
            'outlet_id' => (int) $outletA->id,
            'provider' => 'manual',
            'external_reference' => 'mta-paid-'.Str::random(8),
            'idempotency_key' => 'mta-paid-'.Str::random(8),
            'amount' => 20000,
            'currency' => 'IDR',
            'status' => 'paid',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'reconciliation_attempts' => 1,
            'last_reconciled_at' => now(),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $orderA,
            'outlet_id' => (int) $outletA->id,
            'provider' => 'manual',
            'external_reference' => 'mta-failed-'.Str::random(8),
            'idempotency_key' => 'mta-failed-'.Str::random(8),
            'amount' => 12000,
            'currency' => 'IDR',
            'status' => 'failed',
            'payment_method' => 'qris',
            'reconciliation_attempts' => 2,
            'last_reconciled_at' => now(),
        ]);
        $stale = PaymentTransaction::query()->create([
            'order_id' => $orderA,
            'outlet_id' => (int) $outletA->id,
            'provider' => 'manual',
            'external_reference' => 'mta-stale-'.Str::random(8),
            'idempotency_key' => 'mta-stale-'.Str::random(8),
            'amount' => 8000,
            'currency' => 'IDR',
            'status' => 'pending',
            'payment_method' => 'qris',
            'reconciliation_attempts' => 0,
            'last_async_error' => 'worker timeout',
            'async_retry_after' => now()->addMinutes(3),
        ]);
        PaymentTransaction::query()
            ->whereKey((int) $stale->id)
            ->update(['created_at' => now()->subMinutes(30)]);

        $txForReceipt = PaymentTransaction::query()->create([
            'order_id' => $orderA,
            'outlet_id' => (int) $outletA->id,
            'provider' => 'manual',
            'external_reference' => 'mta-receipt-'.Str::random(8),
            'idempotency_key' => 'mta-receipt-'.Str::random(8),
            'amount' => 7000,
            'currency' => 'IDR',
            'status' => 'pending',
            'payment_method' => 'qris',
            'reconciliation_attempts' => 0,
        ]);

        PaymentWebhookReceipt::query()->create([
            'provider' => 'manual',
            'event_idempotency_key' => 'manual#'.Str::random(8),
            'external_reference' => (string) $txForReceipt->external_reference,
            'incoming_status' => 'paid',
            'payload_hash' => hash('sha256', 'payload'),
            'payload' => ['source' => 'test'],
            'headers' => ['x-test' => '1'],
            'process_attempts' => 2,
            'last_error' => 'deadlock found',
            'next_retry_at' => now()->addMinutes(2),
        ]);

        QrOrderRequest::query()->create([
            'outlet_id' => (int) $outletA->id,
            'table_id' => (int) $tableA->id,
            'request_code' => 'REQ-'.Str::upper(Str::random(8)),
            'status' => 'pending_cashier_confirmation',
            'cashier_call_count' => 2,
            'cashier_called_at' => now()->subSeconds(30),
            'expires_at' => now()->addMinutes(8),
        ]);
        QrOrderRequest::query()->create([
            'outlet_id' => (int) $outletA->id,
            'table_id' => (int) $tableA->id,
            'request_code' => 'REQ-'.Str::upper(Str::random(8)),
            'status' => 'expired',
            'cashier_call_count' => 1,
            'cashier_called_at' => now()->subMinutes(3),
            'expires_at' => now()->subMinute(),
            'confirmed_at' => null,
            'rejected_at' => null,
            'updated_at' => now()->subMinute(),
        ]);
        QrOrderRequest::query()->create([
            'outlet_id' => (int) $outletA->id,
            'table_id' => (int) $tableA->id,
            'request_code' => 'REQ-'.Str::upper(Str::random(8)),
            'status' => 'confirmed',
            'cashier_call_count' => 1,
            'cashier_called_at' => now()->subMinutes(5),
            'confirmed_at' => now()->subMinutes(2),
            'expires_at' => now()->addMinutes(15),
        ]);

        PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outletA->id,
            'type' => 'kitchen',
            'source_type' => 'order',
            'source_id' => $orderA,
            'content' => ['items' => []],
            'status' => 'pending',
            'attempts' => 0,
            'recovery_state' => 'recoverable',
        ]);
        PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outletA->id,
            'type' => 'receipt',
            'source_type' => 'order',
            'source_id' => $orderA,
            'content' => ['items' => []],
            'status' => 'failed',
            'attempts' => 5,
            'recovery_state' => 'dead_letter',
            'last_error' => 'printer unreachable',
        ]);

        $response = $this->getJson('/api/v1/monitoring/metrics?outletId='.(int) $outletA->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.activePosSessions.count', 1);
        $response->assertJsonPath('data.pendingKitchenTickets.count', 1);
        $response->assertJsonPath('data.paymentRate.paidCount', 1);
        $response->assertJsonPath('data.paymentRate.failureCount', 1);
        $response->assertJsonPath('data.stalePayments.count', 1);
        $response->assertJsonPath('data.qrQueue.pendingConfirmation', 1);
        $response->assertJsonPath('data.qrQueue.expired', 1);
        $response->assertJsonPath('data.active_waiter_calls', 1);
        $response->assertJsonPath('data.called_but_unhandled', 1);
        $this->assertGreaterThanOrEqual(0.0, (float) $response->json('data.average_waiter_response_time'));
        $response->assertJsonPath('data.printerQueue.pending', 1);
        $response->assertJsonPath('data.printerQueue.failed', 1);
        $response->assertJsonPath('data.printerQueue.recoverable', 1);
        $response->assertJsonPath('data.printerQueue.deadLetter', 1);
        $response->assertJsonPath('data.reconciliationFailures.count', 1);
        $response->assertJsonPath('data.asyncRecoveryFailures.count', 1);
        $response->assertJsonPath('data.asyncRecoveryFailures.queuedForRetry', 1);
    }

    public function test_metrics_endpoint_accepts_explicit_outlet_scope_for_admin_user(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outletA = $this->createOutlet('MSC');
        $outletB = $this->createOutlet('MSD');
        $this->assignUserToOutlets($user, [(int) $outletA->id]);

        $this->getJson('/api/v1/monitoring/metrics?outletId='.(int) $outletB->id)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function createOutlet(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }

    private function createOrder(int $outletId, ?int $sessionId, string $code): int
    {
        return (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'pos_session_id' => $sessionId,
            'code' => $code.'-'.uniqid(),
            'source' => 'pos',
            'order_channel' => 'dine_in',
            'service_mode' => 'dine_in',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
