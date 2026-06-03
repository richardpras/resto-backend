<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Modules\Reservations\Services\ReservationProjectionAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TableOperationalProjectionService
{
    public function __construct(
        private readonly ReservationProjectionAdapter $reservationProjectionAdapter,
    ) {}

    /**
     * @param  Collection<int, RestaurantTable>  $tables
     * @return array<int, array{status: string, signals: array<string, bool|int>}>
     */
    public function projectForTables(Collection $tables): array
    {
        if ($tables->isEmpty()) {
            return [];
        }

        $tableIds = $tables->pluck('id')->map(fn ($id) => (int) $id)->all();

        $openBillCounts = DB::table('orders')
            ->selectRaw('table_id, COUNT(*) as count')
            ->whereIn('table_id', $tableIds)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('status', '!=', 'cancelled')
            ->groupBy('table_id')
            ->pluck('count', 'table_id');

        $pendingQrCounts = DB::table('qr_order_requests')
            ->selectRaw('table_id, COUNT(*) as count')
            ->whereIn('table_id', $tableIds)
            ->where('status', 'pending_cashier_confirmation')
            ->where('expires_at', '>', now())
            ->groupBy('table_id')
            ->pluck('count', 'table_id');

        $outletId = (int) ($tables->first()->outlet_id ?? 0);
        $reservedTableIds = $this->reservationProjectionAdapter->reservedTableIds($outletId, $tableIds);
        $cleaningTableIds = $this->cleaningTableIds($tableIds);

        $projection = [];
        foreach ($tables as $table) {
            $tableId = (int) $table->id;
            $isDisabled = (string) $table->status !== 'active' || (bool) ($table->active ?? true) === false;
            $hasReservation = in_array($tableId, $reservedTableIds, true);
            $isCleaning = in_array($tableId, $cleaningTableIds, true);
            $openBillCount = (int) ($openBillCounts[$tableId] ?? 0);
            $pendingQrCount = (int) ($pendingQrCounts[$tableId] ?? 0);
            $isOccupied = $openBillCount > 0 || $pendingQrCount > 0;

            $status = 'available';
            if ($isDisabled) {
                $status = 'disabled';
            } elseif ($isOccupied) {
                $status = 'occupied';
            } elseif ($hasReservation) {
                $status = 'reserved';
            } elseif ($isCleaning) {
                $status = 'cleaning';
            }

            $projection[$tableId] = [
                'status' => $status,
                'signals' => [
                    'openBillCount' => $openBillCount,
                    'pendingQrRequestCount' => $pendingQrCount,
                    'hasReservation' => $hasReservation,
                    'isCleaning' => $isCleaning,
                    'isDisabled' => $isDisabled,
                ],
            ];
        }

        return $projection;
    }

    /**
     * @param  list<int>  $tableIds
     * @return list<int>
     */
    private function cleaningTableIds(array $tableIds): array
    {
        // Cleaning workflow is not available yet.
        return [];
    }
}
