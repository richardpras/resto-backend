<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Events\ReservationCreated;
use App\Modules\Reservations\Events\ReservationServiceStarted;
use App\Modules\Reservations\Events\ReservationStatusChanged;
use App\Modules\Reservations\Events\ReservationTableAllocated;
use App\Modules\Reservations\Events\ReservationTableUnallocated;
use App\Modules\Reservations\Events\ReservationUpdated;
use App\Modules\Reservations\Support\ReservationRealtimeSnapshot;

class ReservationRealtimePublisher
{
    public function publishCreated(Reservation $reservation): void
    {
        $snapshot = ReservationRealtimeSnapshot::fromModel($reservation);
        $outletId = (int) $reservation->outlet_id;

        event(new ReservationCreated($outletId, $snapshot));
        event(new ReservationUpdated($outletId, $snapshot));
    }

    public function publishStatusChanged(Reservation $reservation, string $fromStatus, string $toStatus): void
    {
        $snapshot = ReservationRealtimeSnapshot::fromModel($reservation);
        $outletId = (int) $reservation->outlet_id;

        event(new ReservationStatusChanged($outletId, $snapshot, $fromStatus, $toStatus));
        event(new ReservationUpdated($outletId, $snapshot));
    }

    public function publishTableAllocated(Reservation $reservation, int $tableId): void
    {
        $snapshot = ReservationRealtimeSnapshot::fromModel($reservation);
        $outletId = (int) $reservation->outlet_id;

        event(new ReservationTableAllocated($outletId, $snapshot, $tableId));
        event(new ReservationUpdated($outletId, $snapshot));
    }

    public function publishTableUnallocated(Reservation $reservation, int $tableId): void
    {
        $snapshot = ReservationRealtimeSnapshot::fromModel($reservation);
        $outletId = (int) $reservation->outlet_id;

        event(new ReservationTableUnallocated($outletId, $snapshot, $tableId));
        event(new ReservationUpdated($outletId, $snapshot));
    }

    public function publishServiceStarted(Reservation $reservation): void
    {
        $snapshot = ReservationRealtimeSnapshot::fromModel($reservation);
        $outletId = (int) $reservation->outlet_id;

        event(new ReservationServiceStarted($outletId, $snapshot));
        event(new ReservationUpdated($outletId, $snapshot));
    }
}
