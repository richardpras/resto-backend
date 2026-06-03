<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;

class ReservationNoShowService
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    public function processEligibleReservations(?int $graceMinutes = null): int
    {
        $grace = $graceMinutes ?? (int) config('reservations.no_show_grace_minutes', 15);
        $processed = 0;

        $eligibleIds = Reservation::query()
            ->where('status', 'confirmed')
            ->where('reservation_at', '<=', now()->subMinutes($grace))
            ->orderBy('id')
            ->pluck('id');

        foreach ($eligibleIds as $reservationId) {
            if ($this->reservationService->applyAutomatedNoShow((int) $reservationId, $grace) !== null) {
                $processed++;
            }
        }

        return $processed;
    }

    public function isEligibleForAutomaticNoShow(Reservation $reservation, ?int $graceMinutes = null): bool
    {
        if ((string) $reservation->status !== 'confirmed') {
            return false;
        }

        $grace = $graceMinutes ?? (int) config('reservations.no_show_grace_minutes', 15);
        $deadline = $reservation->reservation_at?->copy()->addMinutes($grace);

        return $deadline !== null && now()->greaterThan($deadline);
    }
}
