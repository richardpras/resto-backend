<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ReservationMetricsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(User $user, int $outletId, ?string $from = null, ?string $to = null): array
    {
        $this->assertOutletAllowed($user, $outletId);
        [$fromAt, $toAt] = $this->resolveRange($from, $to);

        $base = Reservation::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('reservation_at', [$fromAt, $toAt]);

        $total = (int) (clone $base)->count();
        $confirmed = (int) (clone $base)->where('status', 'confirmed')->count();
        $checkedIn = (int) (clone $base)->where('status', 'checked_in')->count();
        $seated = (int) (clone $base)->where('status', 'seated')->count();
        $completed = (int) (clone $base)->where('status', 'completed')->count();
        $cancelled = (int) (clone $base)->where('status', 'cancelled')->count();
        $noShow = (int) (clone $base)->where('status', 'no_show')->count();

        $averageCheckinDelay = (float) ((clone $base)
            ->whereNotNull('checked_in_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, reservation_at, checked_in_at)) as avg_minutes')
            ->value('avg_minutes') ?? 0);

        $averageSeatingDelay = (float) ((clone $base)
            ->whereNotNull('checked_in_at')
            ->whereNotNull('seated_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, seated_at)) as avg_minutes')
            ->value('avg_minutes') ?? 0);

        $noShowRate = $total > 0 ? round(($noShow / $total) * 100, 2) : 0.0;

        return [
            'totalReservations' => $total,
            'confirmed' => $confirmed,
            'checkedIn' => $checkedIn,
            'seated' => $seated,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'noShow' => $noShow,
            'noShowRate' => $noShowRate,
            'averageCheckinDelayMinutes' => round($averageCheckinDelay, 2),
            'averageSeatingDelayMinutes' => round($averageSeatingDelay, 2),
            'from' => $fromAt->toISOString(),
            'to' => $toAt->toISOString(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(?string $from, ?string $to): array
    {
        $fromAt = $from !== null && $from !== ''
            ? Carbon::parse($from)->startOfDay()
            : now()->startOfDay();
        $toAt = $to !== null && $to !== ''
            ? Carbon::parse($to)->endOfDay()
            : now()->endOfDay();

        if ($toAt->lessThan($fromAt)) {
            throw ValidationException::withMessages([
                'to' => ['The to date must be on or after the from date.'],
            ]);
        }

        return [$fromAt, $toAt];
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
