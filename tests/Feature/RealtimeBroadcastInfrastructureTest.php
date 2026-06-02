<?php

namespace Tests\Feature;

use App\Broadcasting\OutletRealtimeChannel;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Orders\Events\OrderLifecycleChanged;
use App\Modules\Payments\Events\PaymentStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeBroadcastInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_outlet_channel_authorization_is_scoped(): void
    {
        $user = User::factory()->create();
        $allowedOutlet = Outlet::query()->create([
            'name' => 'Realtime Allowed Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rt-allow-'.uniqid(),
        ]);
        $blockedOutlet = Outlet::query()->create([
            'name' => 'Realtime Blocked Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rt-block-'.uniqid(),
        ]);

        $user->outlets()->attach([(int) $allowedOutlet->id]);

        $channel = new OutletRealtimeChannel;

        $this->assertTrue($channel->join($user, (int) $allowedOutlet->id));
        $this->assertFalse($channel->join($user, (int) $blockedOutlet->id));
    }

    public function test_order_event_payload_contains_version_and_replay_safe_fields(): void
    {
        $event = new OrderLifecycleChanged(
            outletId: 9,
            orderId: 1234,
            status: 'confirmed',
            paymentStatus: 'unpaid',
            kitchenStatus: 'queued',
            sequence: 5,
            aggregateUpdatedAtIso: '2026-05-08T10:00:00+00:00',
            correlationId: 'test-correlation-id'
        );

        $payload = $event->broadcastWith();

        $this->assertSame(1, $payload['event_version']);
        $this->assertSame('order.lifecycle.changed', $payload['event_name']);
        $this->assertSame($payload['event_id'], $payload['id']);
        $this->assertSame('order.lifecycle.changed', $payload['type']);
        $this->assertSame(5, $payload['sequence']);
        $this->assertSame($payload['data'], $payload['payload']);
        $this->assertArrayHasKey('occurredAt', $payload);
        $this->assertSame('test-correlation-id', $payload['meta']['correlation_id']);
        $this->assertSame(5, $payload['meta']['sequence']);
        $this->assertSame('2026-05-08T10:00:00+00:00', $payload['meta']['aggregate_updated_at']);
        $this->assertIsString($payload['meta']['replay_key']);
        $this->assertNotSame('', $payload['meta']['replay_key']);
    }

    public function test_payment_event_payload_exposes_stale_guard_metadata(): void
    {
        $event = new PaymentStatusChanged(
            outletId: 11,
            transactionId: 456,
            orderId: 1234,
            status: 'paid',
            provider: 'manual',
            sequence: 17,
            aggregateUpdatedAtIso: '2026-05-08T10:10:00+00:00',
            correlationId: 'pay-correlation'
        );

        $payload = $event->broadcastWith();

        $this->assertSame(1, $payload['event_version']);
        $this->assertSame('payment.status.changed', $payload['event_name']);
        $this->assertSame($payload['event_id'], $payload['id']);
        $this->assertSame('payment.status.changed', $payload['type']);
        $this->assertSame(17, $payload['sequence']);
        $this->assertSame($payload['data'], $payload['payload']);
        $this->assertSame(17, $payload['data']['meta']['sequence']);
        $this->assertSame('2026-05-08T10:10:00+00:00', $payload['data']['meta']['aggregate_updated_at']);
        $this->assertSame('pay-correlation', $payload['data']['meta']['correlation_id']);
    }
}
