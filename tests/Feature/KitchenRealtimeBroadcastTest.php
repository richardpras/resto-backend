<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Kitchen\Events\KitchenTicketTransitioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class KitchenRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_queued_event_broadcasts_full_snapshot_on_order_create(): void
    {
        Event::fake([KitchenTicketTransitioned::class]);

        [, $outlet] = $this->actAsAdminWithOutlet();
        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'KRT-QUEUED-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '1', 'name' => 'Soup', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
        ])->assertCreated();

        Event::assertDispatched(KitchenTicketTransitioned::class, function (KitchenTicketTransitioned $event): bool {
            return $this->assertSnapshotContract($event, 'queued');
        });
    }

    public function test_in_progress_event_broadcasts_full_snapshot(): void
    {
        Event::fake([KitchenTicketTransitioned::class]);

        [, , $ticketId] = $this->seedTicket();

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertOk();

        Event::assertDispatched(KitchenTicketTransitioned::class, function (KitchenTicketTransitioned $event): bool {
            return $this->assertSnapshotContract($event, 'in_progress');
        });
    }

    public function test_ready_event_broadcasts_full_snapshot(): void
    {
        Event::fake([KitchenTicketTransitioned::class]);

        [, , $ticketId] = $this->seedTicket();

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', ['status' => 'in_progress'])->assertOk();
        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', ['status' => 'ready'])->assertOk();

        Event::assertDispatched(KitchenTicketTransitioned::class, function (KitchenTicketTransitioned $event): bool {
            return $this->assertSnapshotContract($event, 'ready');
        });
    }

    public function test_served_event_broadcasts_full_snapshot(): void
    {
        Event::fake([KitchenTicketTransitioned::class]);

        [, , $ticketId] = $this->seedTicket();

        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', ['status' => 'in_progress'])->assertOk();
        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', ['status' => 'ready'])->assertOk();
        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', ['status' => 'served'])->assertOk();

        Event::assertDispatched(KitchenTicketTransitioned::class, function (KitchenTicketTransitioned $event): bool {
            return $this->assertSnapshotContract($event, 'served');
        });
    }

    private function assertSnapshotContract(KitchenTicketTransitioned $event, string $expectedStatus): bool
    {
        $envelope = $event->broadcastWith();
        $payload = $envelope['payload'] ?? [];
        $channelName = $event->broadcastOn()[0]->name;
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        return $envelope['type'] === 'kitchen.ticket.transitioned'
            && str_ends_with($channelName, '.kitchen')
            && ($payload['status'] ?? null) === $expectedStatus
            && isset($payload['ticket_id'], $payload['ticketId'], $payload['order_id'], $payload['orderId'])
            && isset($payload['order_code'], $payload['orderCode'])
            && array_key_exists('table_number', $payload)
            && array_key_exists('tableNumber', $payload)
            && array_key_exists('service_mode', $payload)
            && array_key_exists('serviceMode', $payload)
            && isset($payload['ticket_no'], $payload['ticketNo'])
            && isset($payload['queued_at'], $payload['queuedAt'])
            && isset($payload['items']) && is_array($payload['items'])
            && isset($meta['sequence'], $meta['replay_key']);
    }

    /** @return array{0: \App\Models\User, 1: Outlet, 2: int} */
    private function seedTicket(?Outlet $outlet = null): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet ??= $this->createOutlet('KRT Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $orderId = (int) $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'KRT-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '1', 'name' => 'Soup', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
        ])->assertCreated()->json('data.id');

        $ticketId = (int) \Illuminate\Support\Facades\DB::table('kitchen_tickets')
            ->where('order_id', $orderId)
            ->value('id');

        return [$user, $outlet, $ticketId];
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('KRT Admin Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name.' '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'krt-'.uniqid(),
        ]);
    }
}
