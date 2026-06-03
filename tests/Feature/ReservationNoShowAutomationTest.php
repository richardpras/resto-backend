<?php

namespace Tests\Feature;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Reservations\Events\ReservationStatusChanged;
use App\Modules\Reservations\Services\ReservationNoShowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationNoShowAutomationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['reservations.no_show_grace_minutes' => 15]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_auto_no_show_applies_to_confirmed_past_grace(): void
    {
        Event::fake([ReservationStatusChanged::class]);

        $reservation = $this->createConfirmedReservationAt(now()->subMinutes(20));

        Artisan::call('reservations:apply-auto-no-show');

        $reservation->refresh();
        $this->assertSame('no_show', (string) $reservation->status);
        $this->assertNotNull($reservation->no_show_at);

        Event::assertDispatched(ReservationStatusChanged::class, function (ReservationStatusChanged $event): bool {
            $payload = $event->broadcastWith();

            return $payload['payload']['from_status'] === 'confirmed'
                && $payload['payload']['to_status'] === 'no_show';
        });
    }

    public function test_grace_period_blocks_early_auto_no_show(): void
    {
        $reservation = $this->createConfirmedReservationAt(now()->subMinutes(10));

        Artisan::call('reservations:apply-auto-no-show');

        $reservation->refresh();
        $this->assertSame('confirmed', (string) $reservation->status);
        $this->assertNull($reservation->no_show_at);
    }

    public function test_checked_in_and_other_statuses_never_auto_transition(): void
    {
        $checkedIn = $this->createConfirmedReservationAt(now()->subHours(2));
        $checkedIn->status = 'checked_in';
        $checkedIn->checked_in_at = now()->subHour();
        $checkedIn->save();

        $seated = $this->createConfirmedReservationAt(now()->subHours(2));
        $seated->status = 'seated';
        $seated->seated_at = now()->subMinutes(30);
        $seated->save();

        $cancelled = $this->createConfirmedReservationAt(now()->subHours(2));
        $cancelled->status = 'cancelled';
        $cancelled->cancelled_at = now();
        $cancelled->save();

        Artisan::call('reservations:apply-auto-no-show');

        $this->assertSame('checked_in', (string) $checkedIn->fresh()->status);
        $this->assertSame('seated', (string) $seated->fresh()->status);
        $this->assertSame('cancelled', (string) $cancelled->fresh()->status);
    }

    public function test_custom_grace_minutes_via_command_option(): void
    {
        $reservation = $this->createConfirmedReservationAt(now()->subMinutes(12));

        Artisan::call('reservations:apply-auto-no-show', ['--grace' => 10]);

        $this->assertSame('no_show', (string) $reservation->fresh()->status);
    }

    public function test_no_show_service_eligibility_helper(): void
    {
        $service = app(ReservationNoShowService::class);
        $eligible = $this->createConfirmedReservationAt(now()->subMinutes(20));
        $notEligible = $this->createConfirmedReservationAt(now()->subMinutes(5));

        $this->assertTrue($service->isEligibleForAutomaticNoShow($eligible));
        $this->assertFalse($service->isEligibleForAutomaticNoShow($notEligible));
    }

    private function createConfirmedReservationAt(Carbon $reservationAt): Reservation
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'NoShow '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        $response = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Late Guest',
            'customerPhone' => '08123',
            'partySize' => 2,
            'reservationAt' => $reservationAt->toISOString(),
        ])->assertCreated();

        $reservationId = (int) $response->json('data.id');
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();

        return Reservation::query()->findOrFail($reservationId);
    }
}
