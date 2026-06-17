<?php

namespace App\Console\Commands;

use App\Modules\Orders\Services\QrGuestSessionService;
use App\Modules\Orders\Services\QrOrderExpiryService;
use Illuminate\Console\Command;

class ExpirePendingQrOrdersCommand extends Command
{
    protected $signature = 'qr-orders:expire-pending';

    protected $description = 'Expire QR order requests that were not confirmed by cashier within TTL';

    public function handle(QrOrderExpiryService $expiryService, QrGuestSessionService $guestSessionService): int
    {
        $expiredOrders = $expiryService->expirePendingRequests();
        $closedSessions = $guestSessionService->closeExpiredSessions();

        $this->info("Expired {$expiredOrders} pending QR order(s); closed {$closedSessions} guest session(s).");

        return self::SUCCESS;
    }
}
