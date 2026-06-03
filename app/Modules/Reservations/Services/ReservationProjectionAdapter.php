<?php

namespace App\Modules\Reservations\Services;

use Illuminate\Support\Facades\DB;

class ReservationProjectionAdapter
{
    /** @var list<string> */
    private const ACTIVE_RESERVATION_STATUSES = ['confirmed', 'checked_in', 'seated'];

    /**
     * Reserved table ids from active reservation allocations for the given outlet.
     *
     * @param  list<int>  $tableIds
     * @return list<int>
     */
    public function reservedTableIds(int $outletId, array $tableIds): array
    {
        if ($tableIds === []) {
            return [];
        }

        return DB::table('reservation_table_allocations as rta')
            ->join('reservations as r', 'r.id', '=', 'rta.reservation_id')
            ->where('r.outlet_id', $outletId)
            ->whereIn('r.status', self::ACTIVE_RESERVATION_STATUSES)
            ->whereIn('rta.table_id', $tableIds)
            ->distinct()
            ->pluck('rta.table_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
