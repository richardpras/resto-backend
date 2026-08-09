<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesDraftReservations;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationOpenBillLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use CreatesDraftReservations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_reservation_linked_order_follows_open_bill_lifecycle(): void
    {
        [$reservationId, $tableId, $outletId] = $this->createSeatedReservationReadyForService();

        $start = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $start->json('linkedOrderId');

        $rowAfterService = $this->tableProjectionRow($outletId, $tableId);
        $this->assertSame('occupied', $rowAfterService['tableOperationalStatus']);
        $this->assertTrue($rowAfterService['tableOperationalSignals']['hasReservation']);

        $menuItem = \App\Models\Modules\Menu\Domain\MenuItem::query()->create([
            'tenant_id' => 1,
            'name' => 'Steak',
            'category' => 'main',
            'price' => 100000,
            'available' => true,
        ]);

        $this->patchJson('/api/v1/orders/'.$linkedOrderId, [
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => 'Steak', 'qty' => 1, 'price' => 100000],
            ],
            'subtotal' => 100000,
            'tax' => 0,
            'total' => 100000,
        ])->assertOk();

        $openBill = $this->getJson('/api/v1/open-bills/table?outletId='.$outletId.'&tableId='.$tableId)->assertOk();
        $openBill->assertJsonPath('data.orderCount', 1);
        $openBill->assertJsonPath('data.remainingPayable', 100000);

        $this->postJson('/api/v1/orders/'.$linkedOrderId.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 40000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();

        $partial = $this->getJson('/api/v1/open-bills/table?outletId='.$outletId.'&tableId='.$tableId)->assertOk();
        $partial->assertJsonPath('data.orderCount', 1);
        $partial->assertJsonPath('data.remainingPayable', 60000);

        $this->postJson('/api/v1/orders/'.$linkedOrderId.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 60000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();

        $settled = $this->getJson('/api/v1/open-bills/table?outletId='.$outletId.'&tableId='.$tableId)->assertOk();
        $settled->assertJsonPath('data.orderCount', 0);
        $settled->assertJsonPath('data.remainingPayable', 0);

        $rowAfterPayment = $this->tableProjectionRow($outletId, $tableId);
        $this->assertSame('reserved', $rowAfterPayment['tableOperationalStatus']);
        $this->assertTrue($rowAfterPayment['tableOperationalSignals']['hasReservation']);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')->assertOk();
    }

    public function test_complete_blocked_while_linked_order_remains_unsettled(): void
    {
        [$reservationId, , ] = $this->createSeatedReservationReadyForService();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('errors.linkedOrder.0', 'Reservation cannot be completed while linked order remains unsettled.');
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function createSeatedReservationReadyForService(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Open Bill '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-OBL',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = $this->insertDraftReservation((int) $outlet->id, [
            'customer_name' => 'Open Bill Guest',
            'party_size' => 4,
        ]);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 100000,
        ])->assertCreated();

        return [$reservationId, $tableId, (int) $outlet->id];
    }

    /** @return array<string, mixed> */
    private function tableProjectionRow(int $outletId, int $tableId): array
    {
        $response = $this->getJson('/api/v1/tables?outletId='.$outletId)->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $tableId);
        self::assertIsArray($row);

        return $row;
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
