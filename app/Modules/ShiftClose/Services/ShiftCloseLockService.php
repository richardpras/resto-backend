<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use App\Modules\ShiftClose\Exceptions\ShiftCloseAlreadyRunningException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class ShiftCloseLockService
{
    private const LOCK_SECONDS = 600;

    public function shiftDate(): string
    {
        return now()->toDateString();
    }

    public function assertNotRunning(int $outletId, ?string $shiftDate = null): void
    {
        $date = $shiftDate ?? $this->shiftDate();

        $running = ShiftCloseRun::query()
            ->where('outlet_id', $outletId)
            ->where('shift_date', $date)
            ->where('status', ShiftCloseRun::STATUS_RUNNING)
            ->exists();

        if ($running) {
            throw new ShiftCloseAlreadyRunningException($outletId, $date);
        }
    }

    public function acquire(int $outletId, ?string $shiftDate = null): Lock
    {
        $date = $shiftDate ?? $this->shiftDate();
        $this->assertNotRunning($outletId, $date);

        $lock = Cache::lock($this->cacheKey($outletId, $date), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new ShiftCloseAlreadyRunningException($outletId, $date);
        }

        return $lock;
    }

    public function release(?Lock $lock): void
    {
        $lock?->release();
    }

    private function cacheKey(int $outletId, string $shiftDate): string
    {
        return "shift-close:{$outletId}:{$shiftDate}";
    }
}
