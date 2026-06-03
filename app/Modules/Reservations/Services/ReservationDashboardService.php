<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ReservationDashboardService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly ReservationMetricsService $metricsService,
    ) {}

    /**
     * @return array{
     *   metrics: array<string, mixed>,
     *   upcomingReservations: Collection<int, Reservation>,
     *   activeReservations: Collection<int, Reservation>,
     *   noShowToday: int
     * }
     */
    public function dashboard(User $user, int $outletId, ?string $from = null, ?string $to = null): array
    {
        $this->assertOutletAllowed($user, $outletId);
        $fromAt = $from !== null && $from !== '' ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $toAt = $to !== null && $to !== '' ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        if ($toAt->lessThan($fromAt)) {
            throw ValidationException::withMessages([
                'to' => ['The to date must be on or after the from date.'],
            ]);
        }

        $upcoming = Reservation::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where('reservation_at', '>=', now())
            ->whereBetween('reservation_at', [$fromAt, $toAt])
            ->orderBy('reservation_at')
            ->orderBy('id')
            ->get();

        $active = Reservation::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', ['checked_in', 'seated'])
            ->orderBy('reservation_at')
            ->orderBy('id')
            ->get();

        $noShowToday = (int) Reservation::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'no_show')
            ->whereDate('no_show_at', now()->toDateString())
            ->count();

        return [
            'metrics' => $this->metricsService->metrics($user, $outletId, $from, $to),
            'upcomingReservations' => $upcoming,
            'activeReservations' => $active,
            'noShowToday' => $noShowToday,
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
}
