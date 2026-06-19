<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationMemberTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_reservation_with_member_links_profile_fields(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('RM');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'MEM-RM-01',
            'full_name' => 'Richard Member',
            'phone' => '081900000001',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => (int) $outlet->id,
            'customerName' => 'Ignored Manual',
            'customerPhone' => '000',
            'memberId' => (int) $member->id,
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated();

        $response->assertJsonPath('data.memberId', (int) $member->id)
            ->assertJsonPath('data.memberNo', 'MEM-RM-01')
            ->assertJsonPath('data.customerName', 'Richard Member')
            ->assertJsonPath('data.customerPhone', '081900000001');

        $this->assertDatabaseHas('reservations', [
            'member_id' => $member->id,
            'customer_name' => 'Richard Member',
        ]);
    }

    public function test_walk_in_reservation_without_member(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('RMW');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/reservations', [
            'outletId' => (int) $outlet->id,
            'customerName' => 'Walk-in Guest',
            'customerPhone' => '081800000001',
            'partySize' => 3,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated()
            ->assertJsonPath('data.memberId', null)
            ->assertJsonPath('data.customerName', 'Walk-in Guest');
    }

    public function test_start_service_propagates_member_to_linked_order(): void
    {
        [$reservationId, , $outletId, $memberId] = $this->createCheckedInReservationReadyForService(withMember: true);

        $response = $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertOk();
        $linkedOrderId = (int) $response->json('linkedOrderId');

        $this->assertDatabaseHas('orders', [
            'id' => $linkedOrderId,
            'member_id' => $memberId,
            'outlet_id' => $outletId,
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'seated',
            'linked_order_id' => $linkedOrderId,
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int|null} */
    private function createCheckedInReservationReadyForService(bool $withMember = false): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('RMS'.uniqid());
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $memberId = null;
        $payload = [
            'outletId' => (int) $outlet->id,
            'customerName' => 'Guest',
            'customerPhone' => '08111',
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ];

        if ($withMember) {
            $member = Member::query()->create([
                'outlet_id' => $outlet->id,
                'member_no' => 'MEM-'.uniqid(),
                'full_name' => 'Linked Member',
                'phone' => '081700000001',
                'is_active' => true,
                'status' => 'active',
                'points' => 0,
            ]);
            $memberId = (int) $member->id;
            $payload['memberId'] = $memberId;
        }

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-RM',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = (int) $this->postJson('/api/v1/reservations', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 100000,
        ])->assertCreated();

        return [$reservationId, $tableId, (int) $outlet->id, $memberId];
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rm-'.uniqid(),
        ]);
    }
}
