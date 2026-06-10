<?php

namespace App\Modules\Payments\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PaymentHealthTelemetryService
{
    /**
     * @return array{
     *   paymentSuccessRate: float,
     *   webhookSuccessRate: float,
     *   stalePayments: int,
     *   failedWebhooks: int,
     *   averageProcessingTimeMs: int
     * }
     */
    public function collect(int $outletId, string $provider, ?Carbon $dateFrom = null, ?Carbon $dateTo = null): array
    {
        $dateFrom ??= now()->subDay()->startOfDay();
        $dateTo ??= now();

        $paymentSuccessRate = $this->paymentSuccessRate($outletId, $provider, $dateFrom, $dateTo);
        $webhookMetrics = $this->webhookMetrics($outletId, $provider, $dateFrom, $dateTo);
        $stalePayments = $this->stalePayments($outletId, $provider);
        $averageProcessingTimeMs = $this->averageProcessingTimeMs($outletId, $provider, $dateFrom, $dateTo);

        return [
            'paymentSuccessRate' => $paymentSuccessRate,
            'webhookSuccessRate' => $webhookMetrics['successRate'],
            'stalePayments' => $stalePayments,
            'failedWebhooks' => $webhookMetrics['failed'],
            'averageProcessingTimeMs' => $averageProcessingTimeMs,
        ];
    }

    private function paymentSuccessRate(int $outletId, string $provider, Carbon $dateFrom, Carbon $dateTo): float
    {
        $base = DB::table('payment_transactions')
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $paidCount = (int) (clone $base)->where('status', 'paid')->count();
        $failureCount = (int) (clone $base)->whereIn('status', ['failed', 'expired', 'cancelled'])->count();
        $denominator = $paidCount + $failureCount;

        if ($denominator === 0) {
            return 100.0;
        }

        return round(($paidCount / $denominator) * 100, 2);
    }

    /**
     * @return array{successRate: float, failed: int}
     */
    private function webhookMetrics(int $outletId, string $provider, Carbon $dateFrom, Carbon $dateTo): array
    {
        $base = DB::table('payment_webhook_receipts as r')
            ->join('payment_transactions as t', function ($join): void {
                $join->on('t.provider', '=', 'r.provider')
                    ->on('t.external_reference', '=', 'r.external_reference');
            })
            ->where('t.outlet_id', $outletId)
            ->where('t.provider', $provider)
            ->whereBetween('r.created_at', [$dateFrom, $dateTo]);

        $processed = (int) (clone $base)->whereNotNull('r.processed_at')->count();
        $failed = (int) (clone $base)
            ->whereNotNull('r.last_error')
            ->whereNull('r.processed_at')
            ->count();

        $denominator = $processed + $failed;
        if ($denominator === 0) {
            return ['successRate' => 100.0, 'failed' => 0];
        }

        return [
            'successRate' => round(($processed / $denominator) * 100, 2),
            'failed' => $failed,
        ];
    }

    private function stalePayments(int $outletId, string $provider): int
    {
        $thresholdMinutes = max(1, (int) config('payments.recovery.stale_pending_minutes', 15));
        $threshold = now()->subMinutes($thresholdMinutes);

        return (int) DB::table('payment_transactions')
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->whereIn('status', ['pending', 'authorized'])
            ->where('created_at', '<=', $threshold)
            ->count();
    }

    private function averageProcessingTimeMs(int $outletId, string $provider, Carbon $dateFrom, Carbon $dateTo): int
    {
        $driver = DB::connection()->getDriverName();
        $avgExpr = $driver === 'sqlite'
            ? 'AVG((julianday(paid_at) - julianday(created_at)) * 86400000.0)'
            : 'AVG(TIMESTAMPDIFF(MICROSECOND, created_at, paid_at) / 1000)';

        $avg = (float) DB::table('payment_transactions')
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->selectRaw($avgExpr.' as v')
            ->value('v');

        return (int) max(0, round($avg));
    }
}
