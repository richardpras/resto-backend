<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase11ConcurrencyHardeningTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'phase11-concurrency-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_duplicate_webhook_and_duplicate_reconciliation_are_idempotent(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11-CC-WH-1');
        $transactionId = $this->createPaymentTransaction($orderId, (int) $outlet->id, 'p11-concur-ext-1', 'p11-concur-idem-1');

        $payload = [
            'externalReference' => 'p11-concur-ext-1',
            'status' => 'paid',
            'eventId' => 'p11-evt-concur-1',
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha256', $raw, (string) config('payments.providers.manual.webhook_secret'));
        $this->withHeaders(['X-Signature' => $signature])->postJson('/api/v1/payment-webhooks/manual', $payload)->assertOk();
        $this->withHeaders(['X-Signature' => $signature])->postJson('/api/v1/payment-webhooks/manual', $payload)->assertOk();

        $this->assertEquals(
            1,
            DB::table('payment_transaction_events')
                ->where('payment_transaction_id', $transactionId)
                ->where('event_type', 'status_changed')
                ->where('payload->to', 'paid')
                ->count()
        );

        $this->postJson('/api/v1/payment-transactions/reconcile', [
            'transactionIds' => [$transactionId],
            'limit' => 10,
        ])->assertOk();
        $this->postJson('/api/v1/payment-transactions/reconcile', [
            'transactionIds' => [$transactionId],
            'limit' => 10,
        ])->assertOk();

        $this->assertDatabaseHas('payment_transactions', ['id' => $transactionId, 'status' => 'paid']);
        $this->assertEquals(
            1,
            DB::table('journals')
                ->where('source_type', 'payment_transaction')
                ->where('source_id', (string) $transactionId)
                ->count()
        );
    }

    public function test_repeated_split_and_concurrent_payment_payloads_do_not_over_allocate(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11-CC-SPLIT-1');
        $orderItemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');
        $staleOrderTimestamp = now()->subDay()->toISOString();

        $payload = [
            'idempotencyKey' => 'p11-split-dup-1',
            'splitType' => 'by_item',
            'label' => 'Guest A',
            'items' => [[
                'orderItemId' => $orderItemId,
                'qty' => 1,
                'amount' => 5000,
            ]],
        ];
        $first = $this->postJson("/api/v1/orders/{$orderId}/splits", $payload);
        $first->assertCreated();
        $duplicate = $this->postJson("/api/v1/orders/{$orderId}/splits", $payload);
        $duplicate->assertUnprocessable();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 5000]],
        ])->assertOk();

        $stale = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'expectedUpdatedAt' => $staleOrderTimestamp,
            'payments' => [['method' => 'transfer', 'amount' => 6000]],
        ]);
        $stale->assertUnprocessable();
    }

    public function test_concurrent_kitchen_updates_and_simultaneous_qr_confirms_are_guarded(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'P11-CC-KITCHEN-1');
        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $orderId)->value('id');
        $staleExpectedUpdatedAt = now()->subDay()->toISOString();

        $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", ['status' => 'in_progress'])->assertOk();
        $stale = $this->patchJson("/api/v1/kitchen/tickets/{$ticketId}/status", [
            'status' => 'ready',
            'expectedUpdatedAt' => $staleExpectedUpdatedAt,
        ]);
        $stale->assertUnprocessable();

        $table = RestaurantTable::query()->create([
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11-QR-C1',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'P11 QR Concurrent',
            'category' => 'main',
            'price' => 10000,
            'available' => true,
        ]);
        $request = $this->postJson('/api/v1/qr-orders', [
            'outletId' => (int) $outlet->id,
            'tableId' => (int) $table->id,
            'customerName' => 'P11 Guest',
            'items' => [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        ]);
        $request->assertCreated();
        $requestId = (int) $request->json('data.id');

        $headers = ['Idempotency-Key' => 'p11-qr-confirm-dup-1'];
        $first = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", [], $headers);
        $first->assertOk();
        $second = $this->postJson("/api/v1/qr-orders/{$requestId}/confirm", [], $headers);
        $second->assertUnprocessable();

        $this->assertEquals(1, DB::table('orders')->where('source', 'qr')->count());
        $this->assertEquals(1, DB::table('kitchen_tickets')->where('order_id', (int) $first->json('data.orderId'))->count());
    }

    /** @return array{0:User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'P11 Concurrency '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p11c-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P11-C-T-'.uniqid(),
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
                ['id' => '811', 'name' => 'Split Item', 'qty' => 1, 'price' => 5000],
                ['id' => '812', 'name' => 'Second Item', 'qty' => 1, 'price' => 6000],
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

        $this->enableGatewayQrisForOutlet($outletId);

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
}
