<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationFoundationApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_reservation(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Main');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'John Doe',
            'customerPhone' => '08123456789',
            'partySize' => 4,
            'reservationAt' => now()->addHour()->toISOString(),
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.outletId', $outlet->id)
            ->assertJsonPath('data.customerName', 'John Doe');
    }

    public function test_confirm_reservation(): void
    {
        $reservationId = $this->createDraftReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_check_in_reservation(): void
    {
        $reservationId = $this->createDraftReservation();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in')
            ->assertJsonPath('data.checkedInAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_seat_reservation(): void
    {
        $reservationId = $this->createDraftReservation();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')
            ->assertOk()
            ->assertJsonPath('data.status', 'seated')
            ->assertJsonPath('data.seatedAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_complete_reservation(): void
    {
        $reservationId = $this->createDraftReservation();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completedAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_cancel_reservation(): void
    {
        $reservationId = $this->createDraftReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelledAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_no_show_reservation(): void
    {
        $reservationId = $this->createDraftReservation();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/mark-no-show')
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show')
            ->assertJsonPath('data.noShowAt', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_invalid_transition_rejection(): void
    {
        $reservationId = $this->createDraftReservation();

        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Invalid reservation status transition.');
    }

    public function test_can_list_and_show_reservations(): void
    {
        $reservationId = $this->createDraftReservation();

        $this->getJson('/api/v1/reservations?outletId='.$this->outletId)
            ->assertOk()
            ->assertJsonPath('data.0.id', $reservationId);

        $this->getJson('/api/v1/reservations/'.$reservationId)
            ->assertOk()
            ->assertJsonPath('data.id', $reservationId);
    }

    private int $outletId;

    private function createDraftReservation(): int
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Main '.uniqid());
        $this->assignUserToOutlets($user, [$outlet->id]);
        $this->outletId = (int) $outlet->id;

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'John Doe',
            'customerPhone' => '08123456789',
            'partySize' => 4,
            'reservationAt' => now()->addHour()->toISOString(),
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
