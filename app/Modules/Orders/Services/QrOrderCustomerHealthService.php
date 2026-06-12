<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrOrderCustomerHealthService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(User $user, ?int $outletId = null): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($outletId !== null && $outletId > 0 && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        $scoped = $outletId !== null && $outletId > 0 ? [$outletId] : $allowed;
        if ($scoped === []) {
            return $this->emptySnapshot();
        }

        $pendingReviews = (int) DB::table('qr_order_requests')
            ->whereIn('outlet_id', $scoped)
            ->whereIn('status', ['pending_cashier_confirmation', 'under_review'])
            ->count();

        $adjustedAwaitingApproval = (int) DB::table('qr_order_requests')
            ->whereIn('outlet_id', $scoped)
            ->where('customer_approval_status', 'pending_approval')
            ->count();

        $callCashierVolume = (int) DB::table('qr_order_requests')
            ->whereIn('outlet_id', $scoped)
            ->where('cashier_call_count', '>', 0)
            ->whereDate('cashier_called_at', now()->toDateString())
            ->sum('cashier_call_count');

        $reviewed = DB::table('qr_order_requests')
            ->whereIn('outlet_id', $scoped)
            ->whereNotNull('reviewed_at')
            ->whereNotNull('created_at')
            ->get(['created_at', 'reviewed_at']);

        $readyRows = DB::table('qr_order_requests as q')
            ->join('orders as o', 'o.id', '=', 'q.order_id')
            ->whereIn('q.outlet_id', $scoped)
            ->where('o.kitchen_status', 'ready')
            ->whereNotNull('q.confirmed_at')
            ->get(['q.confirmed_at', 'o.updated_at']);

        return [
            'pendingReviews' => $pendingReviews,
            'adjustedAwaitingApproval' => $adjustedAwaitingApproval,
            'callCashierVolume' => $callCashierVolume,
            'averageReviewTimeMinutes' => $this->averageMinutes($reviewed, 'created_at', 'reviewed_at'),
            'averageReadyTimeMinutes' => $this->averageMinutes($readyRows, 'confirmed_at', 'updated_at'),
        ];
    }

    /** @return array<string, int|float> */
    private function emptySnapshot(): array
    {
        return [
            'pendingReviews' => 0,
            'adjustedAwaitingApproval' => 0,
            'callCashierVolume' => 0,
            'averageReviewTimeMinutes' => 0.0,
            'averageReadyTimeMinutes' => 0.0,
        ];
    }

    /** @param \Illuminate\Support\Collection<int, object> $rows */
    private function averageMinutes($rows, string $startField, string $endField): float
    {
        $total = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $start = $row->{$startField} ?? null;
            $end = $row->{$endField} ?? null;
            if ($start === null || $end === null) {
                continue;
            }
            $startAt = \Illuminate\Support\Carbon::parse($start);
            $endAt = \Illuminate\Support\Carbon::parse($end);
            if ($endAt->lessThan($startAt)) {
                continue;
            }
            $total += $startAt->diffInSeconds($endAt) / 60;
            $count++;
        }

        return $count > 0 ? round($total / $count, 1) : 0.0;
    }
}
