<?php

namespace Tests\Feature;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationDashboardApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_dashboard_returns_metrics_lists_and_no_show_today(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Dash');
        $this->assignUserToOutlets($user, [$outlet->id]);

        Reservation::query()->create([
            'outlet_id' => $outlet->id,
            'reservation_code' => 'RSV-UP',
            'customer_name' => 'Upcoming',
            'party_size' => 2,
            'reservation_at' => now()->addHours(2),
            'status' => 'confirmed',
        ]);

        Reservation::query()->create([
            'outlet_id' => $outlet->id,
            'reservation_code' => 'RSV-ACT',
            'customer_name' => 'Active',
            'party_size' => 2,
            'reservation_at' => now()->subHour(),
            'checked_in_at' => now()->subMinutes(30),
            'status' => 'checked_in',
        ]);

        Reservation::query()->create([
            'outlet_id' => $outlet->id,
            'reservation_code' => 'RSV-NS',
            'customer_name' => 'No Show',
            'party_size' => 2,
            'reservation_at' => now()->subHours(3),
            'no_show_at' => now(),
            'status' => 'no_show',
        ]);

        $this->getJson('/api/v1/reservations/dashboard?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'metrics' => [
                    'totalReservations',
                    'confirmed',
                    'checkedIn',
                    'seated',
                    'completed',
                    'cancelled',
                    'noShow',
                    'noShowRate',
                    'averageCheckinDelayMinutes',
                    'averageSeatingDelayMinutes',
                ],
                'upcomingReservations',
                'activeReservations',
                'noShowToday',
            ])
            ->assertJsonPath('metrics.totalReservations', 3)
            ->assertJsonPath('noShowToday', 1)
            ->assertJsonCount(1, 'upcomingReservations')
            ->assertJsonCount(1, 'activeReservations');
    }

    public function test_dashboard_rejects_invalid_outlet(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/reservations/dashboard?outletId=99999')
            ->assertUnprocessable();
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
