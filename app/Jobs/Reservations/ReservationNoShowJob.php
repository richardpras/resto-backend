<?php

namespace App\Jobs\Reservations;

use App\Modules\Reservations\Services\ReservationNoShowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReservationNoShowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(ReservationNoShowService $service): void
    {
        $processed = $service->processEligibleReservations();

        Log::info('Reservation automatic no-show job completed.', [
            'processed' => $processed,
            'grace_minutes' => (int) config('reservations.no_show_grace_minutes', 15),
        ]);
    }
}
