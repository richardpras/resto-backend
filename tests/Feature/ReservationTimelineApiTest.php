<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesDraftReservations;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationTimelineApiTest extends TestCase
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

    public function test_timeline_returns_chronological_events(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Timeline');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-1',
            'status' => 'active',
            'active' => true,
        ])->id;

        $reservationId = $this->insertDraftReservation((int) $outlet->id, [
            'customer_name' => 'Timeline Guest',
            'party_size' => 2,
        ]);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $response = $this->getJson('/api/v1/reservations/'.$reservationId.'/timeline')
            ->assertOk()
            ->assertJsonStructure(['data' => [['type', 'label', 'occurredAt']]]);

        $types = collect($response->json('data'))->pluck('type')->all();

        $this->assertContains('reservation.created', $types);
        $this->assertContains('reservation.confirmed', $types);
        $this->assertContains('reservation.table_allocated', $types);
        $this->assertContains('reservation.checked_in', $types);
        $this->assertContains('reservation.seated', $types);

        $occurred = collect($response->json('data'))->pluck('occurredAt')->all();
        $sorted = $occurred;
        sort($sorted);
        $this->assertSame($sorted, $occurred);
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name.' '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-'.uniqid(),
        ]);
    }
}
