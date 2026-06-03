<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationAllocationApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_allocate_single_table(): void
    {
        [$reservationId, $tableId] = $this->createConfirmedReservationWithTable();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $tableId,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.tableId', $tableId);

        $this->getJson('/api/v1/reservations/'.$reservationId.'/allocated-tables')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_allocate_multiple_tables(): void
    {
        [$reservationId, $tableA, $tableB] = $this->createConfirmedReservationWithTwoTables();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableIds' => [$tableA, $tableB],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/reservations/'.$reservationId.'/allocated-tables')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_remove_allocation(): void
    {
        [$reservationId, $tableId] = $this->createConfirmedReservationWithTable();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/unallocate-table', ['tableId' => $tableId])
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertDatabaseMissing('reservation_table_allocations', [
            'reservation_id' => $reservationId,
            'table_id' => $tableId,
        ]);
    }

    public function test_prevent_duplicate_allocation(): void
    {
        [$reservationId, $tableId] = $this->createConfirmedReservationWithTable();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tableId.0', 'Table is already allocated to this reservation.');
    }

    public function test_outlet_mismatch_rejection(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outletA = $this->createOutlet('A');
        $outletB = $this->createOutlet('B');
        $this->assignUserToOutlets($user, [$outletA->id, $outletB->id]);

        $reservationId = $this->createReservationForOutlet($outletA->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        $foreignTableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outletB->id,
            'name' => 'B-1',
            'status' => 'active',
            'active' => true,
        ])->id;

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $foreignTableId,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tableId.0', 'Table not found for this outlet.');
    }

    public function test_reservation_state_validation_blocks_allocation_when_seated(): void
    {
        [$reservationId, $tableId] = $this->createConfirmedReservationWithTable();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $secondTable = RestaurantTable::query()->create([
            'outlet_id' => RestaurantTable::query()->whereKey($tableId)->value('outlet_id'),
            'name' => 'Alt',
            'status' => 'active',
            'active' => true,
        ]);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => (int) $secondTable->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Tables cannot be allocated for this reservation status.');
    }

    public function test_create_reservation_does_not_require_table(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('No Table');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Pak Budi',
            'customerPhone' => '08111',
            'partySize' => 10,
            'reservationAt' => now()->addDay()->toISOString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.tableId', null);
    }

    /** @return array{0: int, 1: int} */
    private function createConfirmedReservationWithTable(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Outlet '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-8',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = $this->createReservationForOutlet($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return [$reservationId, $tableId];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function createConfirmedReservationWithTwoTables(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Outlet2 '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableA = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-8',
            'status' => 'active',
            'active' => true,
        ])->id;
        $tableB = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-9',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = $this->createReservationForOutlet($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return [$reservationId, $tableA, $tableB];
    }

    private function createReservationForOutlet(int $outletId): int
    {
        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outletId,
            'customerName' => 'Pak Budi',
            'customerPhone' => '08111',
            'partySize' => 10,
            'reservationAt' => now()->addHours(2)->toISOString(),
        ])->assertCreated();

        return (int) $response->json('data.id');
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
