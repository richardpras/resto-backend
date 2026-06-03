<?php

namespace App\Console\Commands;

use App\Modules\Reservations\Services\ReservationNoShowService;
use Illuminate\Console\Command;

class ApplyReservationAutoNoShowCommand extends Command
{
    protected $signature = 'reservations:apply-auto-no-show {--grace= : Grace minutes after reservation time}';

    protected $description = 'Mark confirmed reservations as no-show when past reservation time plus grace period';

    public function handle(ReservationNoShowService $service): int
    {
        $grace = $this->option('grace');
        $graceMinutes = $grace !== null && $grace !== '' ? (int) $grace : null;
        $processed = $service->processEligibleReservations($graceMinutes);

        $this->info("Automatic no-show applied to {$processed} reservation(s).");

        return self::SUCCESS;
    }
}
