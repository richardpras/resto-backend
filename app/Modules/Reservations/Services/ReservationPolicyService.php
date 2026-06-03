<?php

namespace App\Modules\Reservations\Services;

class ReservationPolicyService
{
    public function assertTransitionAllowed(string $fromStatus, string $toStatus): void
    {
        if (! $this->canTransition($fromStatus, $toStatus)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Invalid reservation status transition.'],
            ]);
        }
    }

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        $allowed = [
            'draft' => ['confirmed', 'cancelled'],
            'confirmed' => ['checked_in', 'cancelled', 'no_show'],
            'checked_in' => ['seated', 'cancelled'],
            'seated' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
            'no_show' => [],
        ];

        return in_array($toStatus, $allowed[$fromStatus] ?? [], true);
    }

    public function assertAllocationAllowed(string $status): void
    {
        if (! $this->canAllocateOrUnallocate($status)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Tables cannot be allocated for this reservation status.'],
            ]);
        }
    }

    public function canAllocateOrUnallocate(string $status): bool
    {
        return in_array($status, ['draft', 'confirmed', 'checked_in'], true);
    }

    public function assertCheckInAllowed(string $status): void
    {
        $this->assertTransitionAllowed($status, 'checked_in');
    }

    public function assertSeatAllowed(string $status, int $allocatedTableCount): void
    {
        $this->assertTransitionAllowed($status, 'seated');
        if ($allocatedTableCount < 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'allocation' => ['At least one table must be allocated before seating.'],
            ]);
        }
    }

    public function assertCompleteAllowed(string $status, ?string $linkedOrderPaymentStatus = null): void
    {
        $this->assertTransitionAllowed($status, 'completed');

        if ($linkedOrderPaymentStatus !== null && $linkedOrderPaymentStatus !== 'paid') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'linkedOrder' => ['Reservation cannot be completed while linked order remains unsettled.'],
            ]);
        }
    }

    public function assertNoShowAllowed(string $status): void
    {
        $this->assertTransitionAllowed($status, 'no_show');
    }

    public function assertStartServiceAllowed(string $status, ?int $linkedOrderId): void
    {
        if ($status !== 'seated') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Service can only be started for seated reservations.'],
            ]);
        }

        if ($linkedOrderId !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service' => ['Service has already been started for this reservation.'],
            ]);
        }
    }
}
