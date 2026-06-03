<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationCheckInSeatingApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_confirmed_to_checked_in(): void
    {
        $reservationId = $this->createConfirmedReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in')
            ->assertJsonPath('data.checkedInAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_checked_in_to_seated(): void
    {
        [$reservationId, $tableId] = $this->createCheckedInReservationWithTable();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')
            ->assertOk()
            ->assertJsonPath('data.status', 'seated')
            ->assertJsonPath('data.seatedAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_seated_to_completed(): void
    {
        [$reservationId, $tableId] = $this->createCheckedInReservationWithTable();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completedAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_confirmed_to_no_show(): void
    {
        $reservationId = $this->createConfirmedReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/mark-no-show')
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show')
            ->assertJsonPath('data.noShowAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_invalid_transitions_rejected(): void
    {
        $reservationId = $this->createConfirmedReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Invalid reservation status transition.');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Invalid reservation status transition.');
    }

    public function test_seat_requires_allocation(): void
    {
        $reservationId = $this->createConfirmedReservation();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')
            ->assertUnprocessable()
            ->assertJsonPath('errors.allocation.0', 'At least one table must be allocated before seating.');
    }

    public function test_check_in_rejected_from_draft(): void
    {
        $reservationId = $this->createDraftReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Invalid reservation status transition.');
    }

    private function createDraftReservation(): int
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Draft '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Guest',
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated();

        return (int) $response->json('data.id');
    }

    private function createConfirmedReservation(): int
    {
        $reservationId = $this->createDraftReservation();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return $reservationId;
    }

    /** @return array{0: int, 1: int} */
    private function createCheckedInReservationWithTable(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('CheckedIn '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-8',
            'status' => 'active',
            'active' => true,
        ])->id;

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Pak Budi',
            'partySize' => 10,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated();
        $reservationId = (int) $response->json('data.id');

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        return [$reservationId, $tableId];
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
