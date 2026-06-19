<?php

namespace App\Modules\Reservations\Services;

use App\Models\Member;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Reservations\Domain\ReservationTableAllocation;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationPosIntegrationService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly ReservationService $reservationService,
    ) {}

    /** @return array{posSession: array<string, mixed>, loadPayload: array<string, mixed>} */
    public function openInPos(User $user, int $reservationId): array
    {
        return DB::transaction(function () use ($user, $reservationId): array {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);

            if ($reservation->linked_order_id === null) {
                $reservation = $this->reservationService->startService($user, $reservationId);
            }

            $reservation->load(['member', 'linkedOrder', 'tableAllocations.table']);
            $linkedOrder = $reservation->linkedOrder;
            if ($linkedOrder === null) {
                throw ValidationException::withMessages([
                    'linkedOrder' => ['Linked POS order was not found.'],
                ]);
            }

            return [
                'posSession' => [
                    'sessionType' => 'reservation',
                    'reservationCode' => (string) $reservation->reservation_code,
                ],
                'loadPayload' => $this->buildLoadPayload($reservation, $linkedOrder),
            ];
        });
    }

    /**
     * @return array{
     *   readyToStart: \Illuminate\Database\Eloquent\Collection<int, Reservation>,
     *   inService: \Illuminate\Database\Eloquent\Collection<int, Reservation>
     * }
     */
    public function posQueue(User $user, int $outletId): array
    {
        $this->assertOutletAllowed($user, $outletId);

        $with = ['member', 'tableAllocations.table', 'linkedOrder'];

        $checkedIn = Reservation::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'checked_in')
            ->whereNull('linked_order_id')
            ->whereHas('tableAllocations')
            ->with($with)
            ->orderBy('reservation_at')
            ->orderBy('id')
            ->get();

        $seatedWithoutOrder = Reservation::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'seated')
            ->whereNull('linked_order_id')
            ->whereHas('tableAllocations')
            ->with($with)
            ->orderBy('reservation_at')
            ->orderBy('id')
            ->get();

        $readyToStart = $checkedIn->concat($seatedWithoutOrder)->values();

        $inService = Reservation::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'seated')
            ->whereNotNull('linked_order_id')
            ->whereHas('linkedOrder', fn ($q) => $q->where('payment_status', '!=', 'paid'))
            ->with($with)
            ->orderBy('service_started_at')
            ->orderBy('id')
            ->get();

        return [
            'readyToStart' => $readyToStart,
            'inService' => $inService,
        ];
    }

    /** @return array<string, mixed> */
    private function buildLoadPayload(Reservation $reservation, Order $order): array
    {
        $allocation = $reservation->tableAllocations->sortBy('allocated_at')->first();
        $tableId = $allocation !== null
            ? (int) $allocation->table_id
            : ($order->table_id !== null ? (int) $order->table_id : null);
        $tableName = $allocation?->table?->name;

        $member = $reservation->member;
        $memberId = $reservation->member_id !== null ? (int) $reservation->member_id : null;
        if ($memberId === null && $order->member_id !== null) {
            $memberId = (int) $order->member_id;
            $member = Member::query()->whereKey($memberId)->first();
        }

        return [
            'reservationId' => (int) $reservation->id,
            'reservationCode' => (string) $reservation->reservation_code,
            'outletId' => (int) $reservation->outlet_id,
            'linkedOrderId' => (string) $order->id,
            'linkedOrderCode' => (string) $order->code,
            'tableId' => $tableId,
            'tableName' => $tableName,
            'customerName' => (string) ($order->customer_name ?? $reservation->customer_name),
            'customerPhone' => $order->customer_phone ?? $reservation->customer_phone,
            'memberId' => $memberId,
            'memberNo' => $member?->member_no,
            'memberName' => $member?->displayName(),
            'loyaltyAccountId' => $member?->loyalty_account_id !== null ? (string) $member->loyalty_account_id : null,
        ];
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
}
