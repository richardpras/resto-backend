<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Reservations\Events\ReservationCreated;
use App\Modules\Reservations\Events\ReservationServiceStarted;
use App\Modules\Reservations\Events\ReservationStatusChanged;
use App\Modules\Reservations\Events\ReservationTableAllocated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_reservation_created_event_uses_outlet_reservations_channel(): void
    {
        Event::fake([ReservationCreated::class]);

        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Realtime Created');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Ani',
            'customerPhone' => '08123',
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated();

        Event::assertDispatched(ReservationCreated::class, function (ReservationCreated $event): bool {
            $payload = $event->broadcastWith();
            $channelName = $event->broadcastOn()[0]->name;

            return $payload['type'] === 'reservation.created'
                && str_ends_with($channelName, '.reservations')
                && isset($payload['payload']['reservation_id'])
                && isset($payload['payload']['allocated_table_ids'])
                && $payload['payload']['status'] === 'draft';
        });
    }

    public function test_reservation_status_changed_event_emits_on_confirm(): void
    {
        Event::fake([ReservationStatusChanged::class]);

        [$reservationId] = $this->createDraftReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        Event::assertDispatched(ReservationStatusChanged::class, function (ReservationStatusChanged $event): bool {
            $payload = $event->broadcastWith();

            return $payload['type'] === 'reservation.status.changed'
                && $payload['payload']['from_status'] === 'draft'
                && $payload['payload']['to_status'] === 'confirmed'
                && $payload['payload']['fromStatus'] === 'draft'
                && $payload['payload']['toStatus'] === 'confirmed';
        });
    }

    public function test_reservation_table_allocated_event_emits_on_allocate(): void
    {
        Event::fake([ReservationTableAllocated::class]);

        [$reservationId, $tableId] = $this->createConfirmedReservationWithTable();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $tableId,
        ])->assertOk();

        Event::assertDispatched(ReservationTableAllocated::class, function (ReservationTableAllocated $event) use ($tableId): bool {
            $payload = $event->broadcastWith();

            return $payload['type'] === 'reservation.table.allocated'
                && (int) $payload['payload']['table_id'] === $tableId
                && (int) $payload['payload']['tableId'] === $tableId
                && in_array($tableId, $payload['payload']['allocated_table_ids'], true);
        });
    }

    public function test_reservation_service_started_event_emits_on_start_service(): void
    {
        Event::fake([ReservationServiceStarted::class]);

        [$reservationId] = $this->createSeatedReservationReadyForService();

        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        Event::assertDispatched(ReservationServiceStarted::class, function (ReservationServiceStarted $event) use ($linkedOrderId): bool {
            $payload = $event->broadcastWith();

            return $payload['type'] === 'reservation.service.started'
                && $payload['payload']['linked_order_id'] === $linkedOrderId
                && $payload['payload']['linkedOrderId'] === $linkedOrderId
                && $payload['payload']['status'] === 'seated'
                && isset($payload['sequence'])
                && isset($payload['occurredAt'])
                && $payload['payload'] === $payload['data'];
        });
    }

    /** @return array{0: int, 1: Outlet} */
    private function createDraftReservation(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Draft '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Budi',
            'partySize' => 3,
            'reservationAt' => now()->addHours(2)->toISOString(),
        ])->assertCreated();

        return [(int) $response->json('data.id'), $outlet];
    }

    /** @return array{0: int, 1: int} */
    private function createConfirmedReservationWithTable(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Alloc '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-RT',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = (int) $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Citra',
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return [$reservationId, $tableId];
    }

    /** @return array{0: int} */
    private function createSeatedReservationReadyForService(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Service '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-SVC',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = (int) $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Dewi',
            'partySize' => 4,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 100000,
        ])->assertCreated();

        return [$reservationId];
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-'.uniqid(),
        ]);
    }
}
