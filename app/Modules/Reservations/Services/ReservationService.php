<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly ReservationPolicyService $policyService,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Reservation
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        return Reservation::query()->create([
            'outlet_id' => $outletId,
            'table_id' => isset($data['tableId']) ? (int) $data['tableId'] : null,
            'reservation_code' => $this->generateReservationCode(),
            'customer_name' => (string) $data['customerName'],
            'customer_phone' => isset($data['customerPhone']) ? (string) $data['customerPhone'] : null,
            'party_size' => (int) $data['partySize'],
            'reservation_at' => (string) $data['reservationAt'],
            'status' => 'draft',
        ]);
    }

    /** @param array<string, mixed> $filters */
    public function list(User $user, array $filters): Collection
    {
        $outletId = (int) $filters['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        return Reservation::query()
            ->where('outlet_id', $outletId)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->orderBy('reservation_at')
            ->orderBy('id')
            ->get();
    }

    public function show(User $user, int $reservationId): Reservation
    {
        return $this->findScopedOrFail($user, $reservationId);
    }

    public function confirm(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'confirmed');
    }

    public function checkIn(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'checked_in', ['checked_in_at' => now()]);
    }

    public function seat(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'seated', ['seated_at' => now()]);
    }

    public function complete(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'completed', ['completed_at' => now()]);
    }

    public function cancel(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'cancelled', ['cancelled_at' => now()]);
    }

    public function markNoShow(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'no_show', ['no_show_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(User $user, int $reservationId, string $toStatus, array $extra = []): Reservation
    {
        return DB::transaction(function () use ($user, $reservationId, $toStatus, $extra): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);
            $this->policyService->assertTransitionAllowed((string) $reservation->status, $toStatus);

            $reservation->status = $toStatus;
            foreach ($extra as $field => $value) {
                $reservation->setAttribute($field, $value);
            }
            $reservation->save();

            return $reservation->fresh() ?? $reservation;
        });
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function findScopedOrFail(User $user, int $reservationId, bool $lockForUpdate = false): Reservation
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

    private function generateReservationCode(): string
    {
        return 'RSV-'.now()->format('YmdHis').'-'.strtoupper((string) str()->random(4));
    }
}
