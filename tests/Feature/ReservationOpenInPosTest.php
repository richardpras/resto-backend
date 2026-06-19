<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationOpenInPosTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_open_in_pos_starts_service_and_returns_payload(): void
    {
        [$reservationId, $tableId, $outletId, $memberId] = $this->seedCheckedInReservation();

        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/open-in-pos')->assertOk();

        $response->assertJsonPath('posSession.sessionType', 'reservation')
            ->assertJsonPath('loadPayload.reservationId', $reservationId)
            ->assertJsonPath('loadPayload.tableId', $tableId)
            ->assertJsonPath('loadPayload.memberId', $memberId)
            ->assertJsonPath('loadPayload.outletId', $outletId);

        $linkedOrderId = (int) $response->json('loadPayload.linkedOrderId');
        $this->assertGreaterThan(0, $linkedOrderId);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'linked_order_id' => $linkedOrderId,
            'status' => 'seated',
        ]);
    }

    public function test_open_in_pos_is_idempotent_when_service_already_started(): void
    {
        [$reservationId] = $this->seedCheckedInReservation();

        $first = $this->postJson('/api/v1/reservations/'.$reservationId.'/open-in-pos')->assertOk();
        $linkedOrderId = (int) $first->json('loadPayload.linkedOrderId');

        $second = $this->postJson('/api/v1/reservations/'.$reservationId.'/open-in-pos')->assertOk();
        $this->assertSame($linkedOrderId, (int) $second->json('loadPayload.linkedOrderId'));
    }

    public function test_pos_queue_lists_ready_and_in_service_reservations(): void
    {
        [$reservationId, , $outletId] = $this->seedCheckedInReservation();

        $before = $this->getJson('/api/v1/reservations/pos-queue?outletId='.$outletId)->assertOk();
        $before->assertJsonCount(1, 'readyToStart');
        $before->assertJsonPath('readyToStart.0.id', $reservationId);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/open-in-pos')->assertOk();

        $after = $this->getJson('/api/v1/reservations/pos-queue?outletId='.$outletId)->assertOk();
        $after->assertJsonCount(0, 'readyToStart');
        $after->assertJsonCount(1, 'inService');
        $after->assertJsonPath('inService.0.id', $reservationId);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} */
    private function seedCheckedInReservation(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('OIP'.uniqid());
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'MEM-OIP',
            'full_name' => 'POS Guest',
            'phone' => '081600000001',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-OIP',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = (int) $this->postJson('/api/v1/reservations', [
            'outletId' => (int) $outlet->id,
            'customerName' => 'POS Guest',
            'memberId' => (int) $member->id,
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 50000,
        ])->assertCreated();

        return [$reservationId, $tableId, (int) $outlet->id, (int) $member->id];
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'oip-'.uniqid(),
        ]);
    }
}
