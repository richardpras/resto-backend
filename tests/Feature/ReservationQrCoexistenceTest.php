<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationQrCoexistenceTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_reservation_and_qr_request_coexist_on_same_table(): void
    {
        [$outlet, $table, $menuItem, $reservationId] = $this->seedReservedTableWithReservation();

        $rowReserved = $this->tableProjectionRow((int) $outlet->id, (int) $table->id);
        $this->assertSame('reserved', $rowReserved['tableOperationalStatus']);
        $this->assertTrue($rowReserved['tableOperationalSignals']['hasReservation']);

        $requestId = $this->createQrRequest((int) $outlet->id, (int) $table->id, (int) $menuItem->id);

        $rowOccupied = $this->tableProjectionRow((int) $outlet->id, (int) $table->id);
        $this->assertSame('occupied', $rowOccupied['tableOperationalStatus']);
        $this->assertTrue($rowOccupied['tableOperationalSignals']['hasReservation']);
        $this->assertGreaterThan(0, $rowOccupied['tableOperationalSignals']['pendingQrRequestCount']);

        $this->postJson('/api/v1/qr-orders/'.$requestId.'/call-cashier', [
            'outletId' => (int) $outlet->id,
            'tableId' => (int) $table->id,
        ])->assertOk()->assertJsonPath('data.cashierCallCount', 1);

        $this->seedCashierSession((int) $outlet->id);
        $confirm = $this->postJson('/api/v1/qr-orders/'.$requestId.'/confirm', [
            'mode' => 'confirm_only',
        ])->assertOk();
        $qrOrderId = (int) $confirm->json('data.orderId');

        $openBill = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $openBill->assertJsonPath('data.orderCount', 1);
        $openBill->assertJsonPath('data.remainingPayable', 25000);

        $reservation = Reservation::query()->find($reservationId);
        self::assertNotNull($reservation);
        self::assertSame('confirmed', (string) $reservation->status);
        self::assertNull($reservation->linked_order_id);

        $this->assertDatabaseHas('orders', [
            'id' => $qrOrderId,
            'table_id' => (int) $table->id,
            'payment_status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('qr_order_requests', [
            'id' => $requestId,
            'status' => 'confirmed',
        ]);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int} */
    private function seedReservedTableWithReservation(): array
    {
        $staff = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Coexist Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'coexist-'.uniqid(),
        ]);
        $this->assignUserToOutlets($staff, [(int) $outlet->id]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-COEXIST',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu-'.uniqid(),
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);

        $reservationId = (int) $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Reserved Guest',
            'partySize' => 4,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => (int) $table->id,
        ])->assertOk();

        return [$outlet, $table, $menuItem, $reservationId];
    }

    private function createQrRequest(int $outletId, int $tableId, int $menuItemId): int
    {
        $table = RestaurantTable::query()->findOrFail($tableId);
        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            $outletId,
            $tableId,
            $table,
            [['menuItemId' => $menuItemId, 'qty' => 1]],
            ['customerName' => 'QR Guest'],
        );
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    private function seedCashierSession(int $outletId): void
    {
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [$outletId]);
        PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function tableProjectionRow(int $outletId, int $tableId): array
    {
        $response = $this->getJson('/api/v1/tables?outletId='.$outletId)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $tableId);
        self::assertIsArray($row);

        return $row;
    }
}
