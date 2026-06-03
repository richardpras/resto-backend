<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationProjectionIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_reservation_allocated_table_becomes_reserved(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable('T-8');
        $reservationId = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $table->id,
        ])->assertOk();

        $row = $this->tableProjectionRow($outlet->id, (int) $table->id);
        $this->assertSame('reserved', $row['tableOperationalStatus']);
        $this->assertTrue($row['tableOperationalSignals']['hasReservation']);
    }

    public function test_occupied_table_overrides_reserved(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable('T-8');
        $reservationId = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $table->id,
        ])->assertOk();
        $this->seedOpenBillOrder($outlet->id, (int) $table->id);

        $row = $this->tableProjectionRow($outlet->id, (int) $table->id);
        $this->assertSame('occupied', $row['tableOperationalStatus']);
        $this->assertTrue($row['tableOperationalSignals']['hasReservation']);
        $this->assertGreaterThan(0, $row['tableOperationalSignals']['openBillCount']);
    }

    public function test_cancelled_reservation_removes_reserved_status(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable('T-8');
        $reservationId = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $table->id,
        ])->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/cancel')->assertOk();

        $row = $this->tableProjectionRow($outlet->id, (int) $table->id);
        $this->assertSame('available', $row['tableOperationalStatus']);
        $this->assertFalse($row['tableOperationalSignals']['hasReservation']);
    }

    public function test_multiple_tables_reserved_by_one_reservation(): void
    {
        [$outlet, $tableA, $tableB] = $this->seedOutletAndTwoTables();
        $reservationId = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableIds' => [(int) $tableA->id, (int) $tableB->id],
        ])->assertOk();

        $rowA = $this->tableProjectionRow($outlet->id, (int) $tableA->id);
        $rowB = $this->tableProjectionRow($outlet->id, (int) $tableB->id);
        $this->assertSame('reserved', $rowA['tableOperationalStatus']);
        $this->assertSame('reserved', $rowB['tableOperationalStatus']);
        $this->assertTrue($rowA['tableOperationalSignals']['hasReservation']);
        $this->assertTrue($rowB['tableOperationalSignals']['hasReservation']);
    }

    public function test_multiple_reservations_same_outlet(): void
    {
        [$outlet, $tableA, $tableB] = $this->seedOutletAndTwoTables();

        $reservationA = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationA.'/allocate-table', ['tableId' => (int) $tableA->id])->assertOk();

        $reservationB = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationB.'/allocate-table', ['tableId' => (int) $tableB->id])->assertOk();

        $response = $this->getJson('/api/v1/tables?outletId='.$outlet->id)->assertOk();
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame('reserved', $byId->get((int) $tableA->id)['tableOperationalStatus']);
        $this->assertSame('reserved', $byId->get((int) $tableB->id)['tableOperationalStatus']);
    }

    public function test_projection_output_includes_reservation_signal(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable('T-8');
        $reservationId = $this->createConfirmedReservation($outlet->id);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $table->id,
        ])->assertOk();

        $row = $this->tableProjectionRow($outlet->id, (int) $table->id);
        $this->assertArrayHasKey('tableOperationalSignals', $row);
        $this->assertArrayHasKey('hasReservation', $row['tableOperationalSignals']);
        $this->assertTrue($row['tableOperationalSignals']['hasReservation']);
    }

    public function test_draft_allocation_does_not_affect_projection_until_confirmed(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable('T-8');
        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Draft Guest',
            'partySize' => 4,
            'reservationAt' => now()->addHours(2)->toISOString(),
        ])->assertCreated();
        $reservationId = (int) $response->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $table->id,
        ])->assertOk();

        $row = $this->tableProjectionRow($outlet->id, (int) $table->id);
        $this->assertSame('available', $row['tableOperationalStatus']);
        $this->assertFalse($row['tableOperationalSignals']['hasReservation']);
    }

    public function test_seated_reservation_keeps_table_reserved_before_service_starts(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable('T-SEATED');
        $this->createSeatedReservation((int) $outlet->id, (int) $table->id);

        $row = $this->tableProjectionRow($outlet->id, (int) $table->id);
        $this->assertSame('reserved', $row['tableOperationalStatus']);
        $this->assertTrue($row['tableOperationalSignals']['hasReservation']);
    }

    /** @return array<string, mixed> */
    private function tableProjectionRow(int $outletId, int $tableId): array
    {
        $response = $this->getJson('/api/v1/tables?outletId='.$outletId)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $tableId);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array{0: Outlet, 1: RestaurantTable} */
    private function seedOutletAndTable(string $name): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Projection Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'proj-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => $name,
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);

        return [$outlet, $table];
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: RestaurantTable} */
    private function seedOutletAndTwoTables(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Multi Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'multi-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);
        $tableA = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-8',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);
        $tableB = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-9',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);

        return [$outlet, $tableA, $tableB];
    }

    private function createConfirmedReservation(int $outletId): int
    {
        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outletId,
            'customerName' => 'Pak Budi',
            'customerPhone' => '08111',
            'partySize' => 10,
            'reservationAt' => now()->addHours(2)->toISOString(),
        ])->assertCreated();
        $reservationId = (int) $response->json('data.id');
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return $reservationId;
    }

    private function createSeatedReservation(int $outletId, int $tableId): int
    {
        $reservationId = $this->createConfirmedReservation($outletId);
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $tableId,
        ])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        return $reservationId;
    }

    private function seedOpenBillOrder(int $outletId, int $tableId): Order
    {
        return Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'OPEN-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine-in',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 100000,
            'tax' => 10000,
            'total' => 110000,
            'paid_total' => 0,
            'balance_due' => 110000,
            'table_id' => $tableId,
            'table_name' => 'T-8',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
