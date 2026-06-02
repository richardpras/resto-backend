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
}
