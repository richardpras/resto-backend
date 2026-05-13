<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrisPaymentRetryTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.manual.webhook_secret' => 'qris-retry-secret']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_initiating_second_gateway_transaction_supersedes_prior_pending_on_same_order(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'QRIS-RETRY-1');

        $firstId = $this->createPaymentTransaction($orderId, $outlet->id, 'ext-qris-retry-1', 'idem-qris-retry-1');
        $secondId = $this->createPaymentTransaction($orderId, $outlet->id, 'ext-qris-retry-2', 'idem-qris-retry-2');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $firstId,
            'order_id' => $orderId,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $secondId,
            'order_id' => $orderId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('payment_transaction_events', [
            'payment_transaction_id' => $firstId,
            'event_type' => 'superseded',
        ]);
        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());
    }

    public function test_list_order_payments_includes_gateway_attempt_history(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'QRIS-HIST-1');

        $firstId = $this->createPaymentTransaction($orderId, $outlet->id, 'ext-qris-hist-1', 'idem-qris-hist-1');
        $secondId = $this->createPaymentTransaction($orderId, $outlet->id, 'ext-qris-hist-2', 'idem-qris-hist-2');

        $response = $this->getJson('/api/v1/orders/'.$orderId.'/payments');
        $response->assertOk();

        $rows = collect($response->json('data'));
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertTrue($rows->contains(fn (array $row): bool => (string) ($row['source'] ?? '') === 'gateway_transaction'
            && (int) $row['gatewayTransactionId'] === $firstId
            && (string) $row['status'] === 'expired'));
        $this->assertTrue($rows->contains(fn (array $row): bool => (string) ($row['source'] ?? '') === 'gateway_transaction'
            && (int) $row['gatewayTransactionId'] === $secondId
            && (string) $row['status'] === 'pending'));
    }

    public function test_expire_endpoint_marks_pending_transaction_expired(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, (int) $user->id, 'QRIS-EXPIRE-API');
        $transactionId = $this->createPaymentTransaction($orderId, $outlet->id, 'ext-qris-expire-api', 'idem-qris-expire-api');

        $this->postJson('/api/v1/payment-transactions/'.$transactionId.'/expire')
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $transactionId,
            'order_id' => $orderId,
            'status' => 'expired',
        ]);
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

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'QRIS Retry Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qris-retry-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'QRIS-T-'.uniqid(),
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
