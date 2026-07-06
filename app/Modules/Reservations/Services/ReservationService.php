<?php

namespace App\Modules\Reservations\Services;

use App\Models\Member;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Reservations\Domain\ReservationTableAllocation;
use App\Models\User;
use App\Modules\Members\Services\MemberService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Services\OrderService;
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
        private readonly OrderService $orderService,
        private readonly ReservationRealtimePublisher $realtimePublisher,
        private readonly MemberService $memberService,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Reservation
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        $member = $this->resolveOptionalMember($user, $outletId, isset($data['memberId']) ? (int) $data['memberId'] : null);
        $customerName = (string) $data['customerName'];
        $customerPhone = isset($data['customerPhone']) ? (string) $data['customerPhone'] : null;
        if ($member !== null) {
            $customerName = $member->displayName();
            $customerPhone = $member->phone ?: $customerPhone;
        }

        $reservation = Reservation::query()->create([
            'outlet_id' => $outletId,
            'table_id' => null,
            'reservation_code' => $this->generateReservationCode(),
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'member_id' => $member?->id,
            'party_size' => (int) $data['partySize'],
            'reservation_at' => (string) $data['reservationAt'],
            'status' => 'draft',
        ]);
        $fresh = $reservation->fresh(['tableAllocations', 'member']) ?? $reservation;
        $this->realtimePublisher->publishCreated($fresh);

        return $fresh;
    }

    /** @param array<string, mixed> $data */
    public function updateMemberLink(User $user, int $reservationId, array $data): Reservation
    {
        return DB::transaction(function () use ($user, $reservationId, $data): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);
            if (! in_array((string) $reservation->status, ['draft', 'confirmed', 'checked_in'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Member link cannot be changed for this reservation status.'],
                ]);
            }

            $memberId = array_key_exists('memberId', $data) && $data['memberId'] !== null
                ? (int) $data['memberId']
                : null;
            $member = $memberId !== null && $memberId > 0
                ? $this->resolveOptionalMember($user, (int) $reservation->outlet_id, $memberId)
                : null;

            $reservation->member_id = $member?->id;
            if ($member !== null) {
                $reservation->customer_name = $member->displayName();
                $reservation->customer_phone = $member->phone ?: $reservation->customer_phone;
            }
            $reservation->save();

            return $reservation->fresh(['tableAllocations', 'member']) ?? $reservation;
        });
    }

    /** @param array<string, mixed> $filters */
    public function list(User $user, array $filters): Collection
    {
        $outletId = (int) $filters['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        return Reservation::query()
            ->where('outlet_id', $outletId)
            ->with(['member', 'tableAllocations'])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->orderBy('reservation_at')
            ->orderBy('id')
            ->get();
    }

    public function show(User $user, int $reservationId): Reservation
    {
        $reservation = $this->findScopedOrFail($user, $reservationId);
        $reservation->load(['member', 'tableAllocations', 'depositProofs', 'linkedOrder.items']);

        return $reservation;
    }

    public function confirm(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'confirmed', ['confirmed_at' => now()]);
    }

    public function checkIn(User $user, int $reservationId): Reservation
    {
        return $this->transition(
            $user,
            $reservationId,
            'checked_in',
            ['checked_in_at' => now()],
            function (Reservation $reservation): void {
                $this->policyService->assertCheckInAllowed((string) $reservation->status);
            },
        );
    }

    public function seat(User $user, int $reservationId): Reservation
    {
        return $this->transition(
            $user,
            $reservationId,
            'seated',
            ['seated_at' => now()],
            function (Reservation $reservation): void {
                $allocatedTableCount = $reservation->tableAllocations()->count();
                $this->policyService->assertSeatAllowed((string) $reservation->status, $allocatedTableCount);
            },
        );
    }

    public function complete(User $user, int $reservationId): Reservation
    {
        return $this->transition(
            $user,
            $reservationId,
            'completed',
            ['completed_at' => now()],
            function (Reservation $reservation): void {
                $linkedOrderPaymentStatus = null;
                if ($reservation->linked_order_id !== null) {
                    $linkedOrderPaymentStatus = (string) (Order::query()
                        ->whereKey((int) $reservation->linked_order_id)
                        ->value('payment_status') ?? 'unpaid');
                }
                $this->policyService->assertCompleteAllowed(
                    (string) $reservation->status,
                    $linkedOrderPaymentStatus,
                );
            },
        );
    }

    public function cancel(User $user, int $reservationId): Reservation
    {
        return $this->transition($user, $reservationId, 'cancelled', ['cancelled_at' => now()]);
    }

    public function markNoShow(User $user, int $reservationId): Reservation
    {
        return $this->transition(
            $user,
            $reservationId,
            'no_show',
            ['no_show_at' => now()],
            function (Reservation $reservation): void {
                $this->policyService->assertNoShowAllowed((string) $reservation->status);
            },
        );
    }

    public function applyAutomatedNoShow(int $reservationId, ?int $graceMinutes = null): ?Reservation
    {
        return DB::transaction(function () use ($reservationId, $graceMinutes): ?Reservation {
            $reservation = Reservation::query()->whereKey($reservationId)->lockForUpdate()->first();
            if ($reservation === null || (string) $reservation->status !== 'confirmed') {
                return null;
            }

            $grace = $graceMinutes ?? (int) config('reservations.no_show_grace_minutes', 15);
            $deadline = $reservation->reservation_at?->copy()->addMinutes($grace);
            if ($deadline === null || ! now()->greaterThan($deadline)) {
                return null;
            }

            $fromStatus = (string) $reservation->status;
            $this->policyService->assertNoShowAllowed($fromStatus);
            $reservation->status = 'no_show';
            $reservation->no_show_at = now();
            $reservation->save();

            $fresh = $reservation->fresh(['tableAllocations']) ?? $reservation;
            $this->realtimePublisher->publishStatusChanged($fresh, $fromStatus, 'no_show');

            return $fresh;
        });
    }

    public function startService(User $user, int $reservationId): Reservation
    {
        return DB::transaction(function () use ($user, $reservationId): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);
            $fromStatus = (string) $reservation->status;
            $this->policyService->assertStartServiceAllowed(
                $fromStatus,
                $reservation->linked_order_id !== null ? (int) $reservation->linked_order_id : null,
            );

            $allocation = ReservationTableAllocation::query()
                ->where('reservation_id', (int) $reservation->id)
                ->with('table')
                ->orderBy('allocated_at')
                ->orderBy('id')
                ->first();
            if ($allocation === null) {
                throw ValidationException::withMessages([
                    'allocation' => ['At least one allocated table is required to start service.'],
                ]);
            }

            $outletId = (int) $reservation->outlet_id;
            $posSession = PosSession::query()
                ->where('outlet_id', $outletId)
                ->where('status', 'open')
                ->latest('id')
                ->first();
            if ($posSession === null) {
                throw ValidationException::withMessages([
                    'posSessionId' => ['No open POS session for outlet.'],
                ]);
            }

            $tableId = (int) $allocation->table_id;
            $startedAt = now();

            if ($fromStatus === 'checked_in') {
                $reservation->status = 'seated';
                $reservation->seated_at = $startedAt;
                $reservation->save();
                $this->realtimePublisher->publishStatusChanged(
                    $reservation->fresh(['tableAllocations']) ?? $reservation,
                    'checked_in',
                    'seated',
                );
            }

            $existingOrderId = $reservation->linked_order_id !== null ? (int) $reservation->linked_order_id : null;
            if ($existingOrderId !== null) {
                $order = Order::query()->whereKey($existingOrderId)->lockForUpdate()->first();
                if ($order === null) {
                    throw ValidationException::withMessages([
                        'linkedOrder' => ['Linked pre-order was not found.'],
                    ]);
                }
                $order->table_id = $tableId;
                $order->pos_session_id = (int) $posSession->id;
                $order->service_mode = 'dine_in';
                $order->order_channel = $order->order_channel ?: 'reservation_deposit';
                $order->save();
            } else {
                $order = $this->normalizeZeroTotalServiceShell($this->orderService->create(
                    new CreateOrderData(
                        tenantId: 1,
                        outletId: $outletId,
                        code: $this->generateServiceOrderCode($reservation),
                        source: 'pos',
                        orderType: 'Dine In',
                        status: 'confirmed',
                        paymentStatus: 'unpaid',
                        items: [],
                        payments: [],
                        subtotal: 0,
                        tax: 0,
                        total: 0,
                        discountAmount: 0,
                        customerName: (string) $reservation->customer_name,
                        customerPhone: $reservation->customer_phone,
                        memberId: $reservation->member_id !== null ? (int) $reservation->member_id : null,
                        tableId: $tableId,
                        tableNumber: null,
                        createdAt: $startedAt->toISOString(),
                        confirmedAt: $startedAt->toISOString(),
                        splitBill: null,
                        serviceMode: 'dine_in',
                        orderChannel: 'dine_in',
                        posSessionId: (int) $posSession->id,
                    ),
                    $user,
                ));

                $reservation->linked_order_id = (int) $order->id;
            }

            $reservation->service_started_at = $startedAt;
            $reservation->save();

            $fresh = $reservation->fresh(['linkedOrder', 'tableAllocations', 'member']) ?? $reservation;
            $this->realtimePublisher->publishServiceStarted($fresh);

            return $fresh;
        });
    }

    private function resolveOptionalMember(User $user, int $outletId, ?int $memberId): ?Member
    {
        if ($memberId === null || $memberId < 1) {
            return null;
        }

        $member = $this->memberService->findForOutlet($user, $memberId, $outletId);
        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found for this outlet.'],
            ]);
        }

        $isActive = $member->is_active ?? (($member->status ?? 'active') === 'active');
        if (! $isActive) {
            throw ValidationException::withMessages([
                'memberId' => ['Member is inactive.'],
            ]);
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  callable(Reservation): void|null  $beforeTransition
     */
    private function transition(
        User $user,
        int $reservationId,
        string $toStatus,
        array $extra = [],
        ?callable $beforeTransition = null,
    ): Reservation {
        return DB::transaction(function () use ($user, $reservationId, $toStatus, $extra, $beforeTransition): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);
            $fromStatus = (string) $reservation->status;
            if ($beforeTransition !== null) {
                $beforeTransition($reservation);
            } else {
                $this->policyService->assertTransitionAllowed($fromStatus, $toStatus);
            }

            $reservation->status = $toStatus;
            foreach ($extra as $field => $value) {
                $reservation->setAttribute($field, $value);
            }
            $reservation->save();

            $fresh = $reservation->fresh(['tableAllocations']) ?? $reservation;
            if ($fromStatus !== $toStatus) {
                $this->realtimePublisher->publishStatusChanged($fresh, $fromStatus, $toStatus);
            }

            return $fresh;
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

    private function generateServiceOrderCode(Reservation $reservation): string
    {
        return 'RSV-SVC-'.(string) $reservation->reservation_code.'-'.strtoupper((string) str()->random(3));
    }

    /**
     * OrderService treats zero-total orders as fully paid; service shells must stay open for POS item entry.
     */
    private function normalizeZeroTotalServiceShell(Order $order): Order
    {
        if ((float) $order->total > 0 || (string) $order->payment_status !== 'paid') {
            return $order;
        }

        $order->payment_status = 'unpaid';
        $order->status = 'confirmed';
        $order->paid_total = 0;
        $order->balance_due = 0;
        $order->save();

        return $order->fresh() ?? $order;
    }
}
