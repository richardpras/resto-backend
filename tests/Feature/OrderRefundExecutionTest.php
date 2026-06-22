<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemRecoveryEvent;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrderRefundExecutionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(\Database\Seeders\UserManagementPermissionsSeeder::class);
    }

    public function test_execute_cash_refund_after_settlement_reduces_paid_total(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Refund Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        [$orderId, $itemId] = $this->seedPaidPosOrder($outlet->id, 'REF-EXEC-1');

        DB::table('payments')->insert([
            'order_id' => $orderId,
            'method' => 'cash',
            'amount' => 10000,
            'status' => 'paid',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/approve", [
            'resolution' => 'recovery_approved',
        ])->assertOk();

        $idem = 'refund-test-'.uniqid();
        $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/settlement/record", [
            'settlementKind' => 'composite',
            'partialRefundAmount' => 5000,
            'storeCreditAmount' => 0,
            'giftCardAmount' => 0,
            'idempotencyKey' => $idem,
        ])->assertOk();

        $execute = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/refund/execute", [
            'method' => 'cash',
            'amount' => 5000,
            'idempotencyKey' => 'exec-'.$idem,
        ]);
        $execute->assertOk();
        self::assertFalse($execute->json('data.idempotent'));

        self::assertSame(5000.0, (float) DB::table('orders')->where('id', $orderId)->value('paid_total'));
        self::assertSame(1, Payment::query()->where('order_id', $orderId)->where('status', 'refund')->count());
        self::assertSame(1, OrderItemRecoveryEvent::query()->where('event_code', 'refund_executed')->count());

        $retry = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/refund/execute", [
            'method' => 'cash',
            'amount' => 5000,
            'idempotencyKey' => 'exec-'.$idem,
        ]);
        $retry->assertOk();
        self::assertTrue($retry->json('data.idempotent'));
    }

    public function test_refund_allowed_on_order_with_closed_pos_session(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Closed Session Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $sessionId = DB::table('pos_sessions')->insertGetId([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'closed',
            'opening_cash' => 500000,
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$orderId, $itemId] = $this->seedPaidPosOrder($outlet->id, 'REF-CLOSED-1', (int) $sessionId);

        DB::table('payments')->insert([
            'order_id' => $orderId,
            'method' => 'cash',
            'amount' => 8000,
            'status' => 'paid',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/approve", ['resolution' => 'refunded'])->assertOk();
        $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/settlement/record", [
            'partialRefundAmount' => 8000,
            'idempotencyKey' => 'settle-closed-'.uniqid(),
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/refund/execute", [
            'method' => 'cash',
            'amount' => 8000,
            'idempotencyKey' => 'exec-closed-'.uniqid(),
        ])->assertOk();
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ref-'.uniqid(),
        ]);
    }

    /** @return array{0: int, 1: int} */
    private function seedPaidPosOrder(int $outletId, string $code, ?int $posSessionId = null): array
    {
        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'pos_session_id' => $posSessionId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 10000,
            'balance_due' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => '101',
            'name' => 'Nasi',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
            'recovery_status' => 'recovery_pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $orderId, (int) $itemId];
    }
}
