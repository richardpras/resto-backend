<?php

namespace App\Modules\LoyaltyEngine\Jobs;

use App\Modules\LoyaltyEngine\Services\LoyaltyExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LoyaltyExpiryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(LoyaltyExpiryService $expiryService): void
    {
        $expiryService->processAllPrograms();
    }
}
