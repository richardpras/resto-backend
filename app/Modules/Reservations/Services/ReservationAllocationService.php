<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Reservations\Domain\ReservationTableAllocation;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationAllocationService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly ReservationPolicyService $policyService,
        private readonly ReservationRealtimePublisher $realtimePublisher,
    ) {}

    /**
     * @param  array{tableId?: int, tableIds?: list<int>}  $data
     * @return Collection<int, ReservationTableAllocation>
     */
    public function allocateTables(User $user, int $reservationId, array $data): Collection
    {
        $tableIds = $this->resolveTableIds($data);

        return DB::transaction(function () use ($user, $reservationId, $tableIds): Collection {
            $reservation = $this->findScopedReservationOrFail($user, $reservationId, true);
            $this->policyService->assertAllocationAllowed((string) $reservation->status);

            foreach ($tableIds as $tableId) {
                $this->assertTableInOutlet((int) $reservation->outlet_id, $tableId);

                $exists = ReservationTableAllocation::query()
                    ->where('reservation_id', (int) $reservation->id)
                    ->where('table_id', $tableId)
                    ->exists();
                if ($exists) {
                    throw ValidationException::withMessages([
                        'tableId' => ['Table is already allocated to this reservation.'],
                    ]);
                }

                ReservationTableAllocation::query()->create([
                    'reservation_id' => (int) $reservation->id,
                    'table_id' => $tableId,
                    'allocated_at' => now(),
                    'allocated_by_user_id' => (int) $user->id,
                ]);

                $freshReservation = $reservation->fresh(['tableAllocations']) ?? $reservation;
                $this->realtimePublisher->publishTableAllocated($freshReservation, $tableId);
            }

            return $this->listAllocatedTables($user, $reservationId);
        });
    }

    public function unallocateTable(User $user, int $reservationId, int $tableId): Collection
    {
        return DB::transaction(function () use ($user, $reservationId, $tableId): Collection {
            $reservation = $this->findScopedReservationOrFail($user, $reservationId, true);
            $this->policyService->assertAllocationAllowed((string) $reservation->status);

            $allocation = ReservationTableAllocation::query()
                ->where('reservation_id', (int) $reservation->id)
                ->where('table_id', $tableId)
                ->first();

            if ($allocation === null) {
                throw ValidationException::withMessages([
                    'tableId' => ['Table is not allocated to this reservation.'],
                ]);
            }

            $allocation->delete();

            $freshReservation = $reservation->fresh(['tableAllocations']) ?? $reservation;
            $this->realtimePublisher->publishTableUnallocated($freshReservation, $tableId);

            return $this->listAllocatedTables($user, $reservationId);
        });
    }

    /** @return Collection<int, ReservationTableAllocation> */
    public function listAllocatedTables(User $user, int $reservationId): Collection
    {
        $reservation = $this->findScopedReservationOrFail($user, $reservationId);

        return ReservationTableAllocation::query()
            ->where('reservation_id', (int) $reservation->id)
            ->with(['table:id,outlet_id,name,code,capacity'])
            ->orderBy('allocated_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{tableId?: int, tableIds?: list<int>}  $data
     * @return list<int>
     */
    private function resolveTableIds(array $data): array
    {
        $ids = [];
        if (isset($data['tableIds']) && is_array($data['tableIds'])) {
            foreach ($data['tableIds'] as $id) {
                $ids[] = (int) $id;
            }
        } elseif (isset($data['tableId'])) {
            $ids[] = (int) $data['tableId'];
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw ValidationException::withMessages([
                'tableId' => ['At least one tableId or tableIds entry is required.'],
            ]);
        }

        return $ids;
    }

    private function assertTableInOutlet(int $outletId, int $tableId): void
    {
        $exists = RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->whereKey($tableId)
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'tableId' => ['Table not found for this outlet.'],
            ]);
        }
    }

    private function findScopedReservationOrFail(User $user, int $reservationId, bool $lockForUpdate = false): Reservation
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $query = Reservation::query()
            ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
            ->whereKey($reservationId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $reservation = $query->first();
        if ($reservation === null) {
            throw (new ModelNotFoundException)->setModel(Reservation::class, [(string) $reservationId]);
        }

        return $reservation;
    }
}
