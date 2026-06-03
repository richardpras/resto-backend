<?php

namespace Tests\Feature;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Reservations\Services\ReservationMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationMetricsTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_metrics_counts_and_rates_by_outlet_and_range(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Metrics');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $baseAt = now()->startOfDay()->addHours(12);

        $this->seedReservation($outlet->id, 'confirmed', $baseAt);
        $this->seedReservation($outlet->id, 'checked_in', $baseAt, [
            'checked_in_at' => $baseAt->copy()->addMinutes(10),
        ]);
        $this->seedReservation($outlet->id, 'seated', $baseAt, [
            'checked_in_at' => $baseAt->copy()->addMinutes(5),
            'seated_at' => $baseAt->copy()->addMinutes(20),
        ]);
        $this->seedReservation($outlet->id, 'completed', $baseAt);
        $this->seedReservation($outlet->id, 'cancelled', $baseAt);
        $this->seedReservation($outlet->id, 'no_show', $baseAt, ['no_show_at' => $baseAt->copy()->addHour()]);

        $service = app(ReservationMetricsService::class);
        $metrics = $service->metrics(
            $user,
            (int) $outlet->id,
            now()->toDateString(),
            now()->toDateString(),
        );

        $this->assertSame(6, $metrics['totalReservations']);
        $this->assertSame(1, $metrics['confirmed']);
        $this->assertSame(1, $metrics['checkedIn']);
        $this->assertSame(1, $metrics['seated']);
        $this->assertSame(1, $metrics['completed']);
        $this->assertSame(1, $metrics['cancelled']);
        $this->assertSame(1, $metrics['noShow']);
        $this->assertSame(16.67, $metrics['noShowRate']);
        $this->assertSame(7.5, $metrics['averageCheckinDelayMinutes']);
        $this->assertSame(15.0, $metrics['averageSeatingDelayMinutes']);
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

    /** @param array<string, mixed> $extra */
    private function seedReservation(int $outletId, string $status, Carbon $reservationAt, array $extra = []): void
    {
        Reservation::query()->create(array_merge([
            'outlet_id' => $outletId,
            'reservation_code' => 'RSV-'.uniqid(),
            'customer_name' => 'Guest',
            'customer_phone' => '081',
            'party_size' => 2,
            'reservation_at' => $reservationAt,
            'status' => $status,
        ], $extra));
    }
}
