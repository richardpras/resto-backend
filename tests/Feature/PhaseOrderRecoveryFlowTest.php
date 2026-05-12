<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItemRecoveryEvent;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PhaseOrderRecoveryFlowTest extends TestCase
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

    public function test_report_then_approve_emits_recovery_events_and_updates_item(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Recovery Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $orderId = $this->seedPaidOrderWithItem($outlet->id, 'REC-ORD-1');
        $itemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $report = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/report", [
            'targetStatus' => 'recovery_pending',
            'reason' => 'Sold out after payment',
        ]);
        $report->assertOk();
        self::assertSame('recovery_pending', (string) DB::table('order_items')->where('id', $itemId)->value('recovery_status'));

        $events = $this->getJson("/api/v1/orders/{$orderId}/recovery-events");
        $events->assertOk();
        self::assertGreaterThanOrEqual(1, count($events->json('data')));

        $approve = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/approve", [
            'resolution' => 'recovery_approved',
            'notes' => 'Manager approved store credit path later',
        ]);
        $approve->assertOk();
        self::assertSame('recovery_approved', (string) DB::table('order_items')->where('id', $itemId)->value('recovery_status'));
        self::assertNotNull(DB::table('order_items')->where('id', $itemId)->value('recovery_approved_at'));

        self::assertGreaterThanOrEqual(2, OrderItemRecoveryEvent::query()->where('order_item_id', $itemId)->count());
    }

    public function test_recovery_settlement_preview_and_record_is_idempotent(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Recovery Settlement Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $orderId = $this->seedPaidOrderWithItem($outlet->id, 'REC-SET-1');
        $itemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $preview = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/settlement/preview", [
            'settlementKind' => 'composite',
            'partialRefundAmount' => 2500,
            'storeCreditAmount' => 500,
            'giftCardAmount' => 0,
        ]);
        $preview->assertOk();
        $preview->assertJsonPath('data.refund.capped', 2500);

        $idem = 'settlement-idem-'.uniqid();
        $body = [
            'settlementKind' => 'composite',
            'partialRefundAmount' => 2500,
            'storeCreditAmount' => 500,
            'giftCardAmount' => 0,
            'idempotencyKey' => $idem,
            'notes' => 'Manager recorded settlement plan',
        ];

        $r1 = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/settlement/record", $body);
        $r1->assertOk();
        self::assertFalse($r1->json('data.idempotent'));

        $r2 = $this->postJson("/api/v1/orders/{$orderId}/items/{$itemId}/recovery/settlement/record", $body);
        $r2->assertOk();
        self::assertTrue($r2->json('data.idempotent'));

        self::assertSame(
            1,
            OrderItemRecoveryEvent::query()
                ->where('order_item_id', $itemId)
                ->where('event_code', 'recovery_settlement_recorded')
                ->count(),
        );
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rec-'.uniqid(),
        ]);
    }

    private function seedPaidOrderWithItem(int $outletId, string $code): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
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

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => '101',
            'name' => 'Nasi',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $orderId;
    }
}
