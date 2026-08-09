<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\CreatesDraftReservations;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationPosIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use CreatesDraftReservations;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_seated_reservation_can_start_service(): void
    {
        [$reservationId] = $this->createSeatedReservationReadyForService();

        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();

        $response->assertJsonPath('data.status', 'seated')
            ->assertJsonPath('data.linkedOrderId', fn ($value) => is_int($value) && $value > 0)
            ->assertJsonPath('linkedOrderId', fn ($value) => is_int($value) && $value > 0)
            ->assertJsonPath('serviceStartedAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_non_seated_reservation_cannot_start_service(): void
    {
        $reservationId = $this->createConfirmedReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Service can only be started for checked-in or seated reservations.');
    }

    public function test_checked_in_reservation_can_start_service_without_manual_seat(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('CheckedIn Start '.uniqid());
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-CI',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = $this->createDraftReservationForOutlet((int) $outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 100000,
        ])->assertCreated();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'seated',
        ]);
    }

    public function test_service_cannot_start_twice(): void
    {
        [$reservationId] = $this->createSeatedReservationReadyForService();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')
            ->assertUnprocessable()
            ->assertJsonPath('errors.service.0', 'Service has already been started for this reservation.');
    }

    public function test_linked_order_stored_on_reservation(): void
    {
        [$reservationId] = $this->createSeatedReservationReadyForService();

        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'linked_order_id' => $linkedOrderId,
        ]);
        $this->assertNotNull(Reservation::query()->find($reservationId)?->service_started_at);
    }

    public function test_created_order_is_dine_in(): void
    {
        [$reservationId, $tableId, $outletId] = $this->createSeatedReservationReadyForService();

        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        $order = Order::query()->find($linkedOrderId);
        self::assertNotNull($order);
        self::assertSame('dine_in', (string) $order->service_mode);
        self::assertSame('dine_in', (string) $order->order_channel);
        self::assertSame('Dine In', (string) $order->order_type);
        self::assertSame('pos', (string) $order->source);
        self::assertSame($tableId, (int) $order->table_id);
        self::assertSame($outletId, (int) $order->outlet_id);
        self::assertSame('unpaid', (string) $order->payment_status);
    }

    public function test_open_bill_aggregation_includes_created_order(): void
    {
        [$reservationId, $tableId, $outletId] = $this->createSeatedReservationReadyForService();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();

        $openBill = $this->getJson('/api/v1/open-bills/table?outletId='.$outletId.'&tableId='.$tableId)->assertOk();
        $openBill->assertJsonPath('data.orderCount', 1);
        $openBill->assertJsonPath('data.remainingPayable', 0);
    }

    public function test_kitchen_lifecycle_for_linked_order_uses_existing_flow(): void
    {
        [$reservationId, , $outletId] = $this->createSeatedReservationReadyForService();
        $menuItem = $this->createMenuItem($outletId);
        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        $this->patchJson('/api/v1/orders/'.$linkedOrderId, [
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => $menuItem->name, 'qty' => 1, 'price' => 15000],
            ],
            'subtotal' => 15000,
            'tax' => 1500,
            'total' => 16500,
        ])->assertOk();

        $this->assertDatabaseHas('kitchen_tickets', [
            'order_id' => $linkedOrderId,
            'status' => 'queued',
        ]);

        $ticketId = (int) DB::table('kitchen_tickets')->where('order_id', $linkedOrderId)->value('id');
        $this->patchJson('/api/v1/kitchen/tickets/'.$ticketId.'/status', [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_payment_lifecycle_for_linked_order_uses_existing_flow(): void
    {
        [$reservationId, , $outletId] = $this->createSeatedReservationReadyForService();
        $menuItem = $this->createMenuItem($outletId, 'Water');
        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        $this->patchJson('/api/v1/orders/'.$linkedOrderId, [
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => $menuItem->name, 'qty' => 1, 'price' => 15000],
            ],
            'subtotal' => 15000,
            'tax' => 0,
            'total' => 15000,
        ])->assertOk();

        $this->postJson('/api/v1/orders/'.$linkedOrderId.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 15000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();

        $order = Order::query()->find($linkedOrderId);
        self::assertNotNull($order);
        self::assertSame('paid', (string) $order->payment_status);
    }

    public function test_accounting_lifecycle_for_linked_order_uses_existing_flow(): void
    {
        [$reservationId, , $outletId] = $this->createSeatedReservationReadyForService();
        $this->seedPosPostingAccounts($outletId);
        $this->setRevenuePostingMode('on_payment', $outletId);

        $menuItem = $this->createMenuItem($outletId, 'Tea');
        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        $this->patchJson('/api/v1/orders/'.$linkedOrderId, [
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => $menuItem->name, 'qty' => 1, 'price' => 20000],
            ],
            'subtotal' => 20000,
            'tax' => 0,
            'total' => 20000,
        ])->assertOk();

        $this->postJson('/api/v1/orders/'.$linkedOrderId.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 20000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('journals', [
            'source_type' => 'order_payment',
            'source_id' => (string) $linkedOrderId,
            'outlet_id' => $outletId,
        ]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function createSeatedReservationReadyForService(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('POS Integration '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-10',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = $this->createDraftReservationForOutlet((int) $outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 100000,
        ])->assertCreated();

        return [$reservationId, $tableId, (int) $outlet->id];
    }

    private function createConfirmedReservation(): int
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Confirmed '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);
        $reservationId = $this->createDraftReservationForOutlet((int) $outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return $reservationId;
    }

    private function createDraftReservationForOutlet(int $outletId): int
    {
        return $this->insertDraftReservation($outletId, [
            'customer_name' => 'Pak Budi',
            'customer_phone' => '08111',
            'party_size' => 4,
        ]);
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

    private function createMenuItem(int $outletId, string $name = 'POS Item'): MenuItem
    {
        return MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => $name,
            'category' => 'main',
            'price' => 15000,
            'available' => true,
        ]);
    }

}
