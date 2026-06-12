<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\Orders\Domain\PosSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShiftCloseCashReconciliationService
{
    /** @var list<string> */
    private const CASH_METHODS = ['cash', 'CASH'];

    /**
     * @return array<string, mixed>
     */
    public function reconcile(int $outletId, ?float $actualCash, ?int $posSessionId = null): array
    {
        $session = $this->resolveSession($outletId, $posSessionId);
        $openingCash = $session !== null ? (float) $session->opening_cash : 0.0;

        $cashSales = $this->sumCashSales($outletId, $session);
        $cashRefunds = $this->sumCashRefunds($outletId, $session);
        $nonCashSales = $this->sumNonCashSales($outletId, $session);
        $totalSales = round($cashSales + $nonCashSales, 2);

        $cashExpenses = $this->sumCashExpenses($outletId, $session);
        $cashIn = $this->sumCashIn($outletId, $session);
        $cashOut = $this->sumCashOut($outletId, $session);

        $limitations = [];
        if ($cashExpenses === 0.0 && ! $this->hasCashMovementTable('pos_session_cash_expenses')) {
            $limitations[] = 'cash_expenses_unavailable';
        }
        if ($cashIn === 0.0 && ! $this->hasCashMovementTable('pos_session_cash_movements')) {
            $limitations[] = 'cash_in_out_unavailable';
        }

        $expected = round($openingCash + $cashSales - $cashRefunds - $cashExpenses + $cashIn - $cashOut, 2);
        $actual = $actualCash !== null ? round($actualCash, 2) : null;
        $variance = $actual !== null ? round($actual - $expected, 2) : null;

        $status = 'unknown';
        if ($variance !== null) {
            if (abs($variance) < 0.01) {
                $status = 'balanced';
            } elseif ($variance > 0) {
                $status = 'over';
            } else {
                $status = 'short';
            }
        }

        return [
            'openingCash' => $openingCash,
            'cashSales' => round($cashSales, 2),
            'cashRefunds' => round($cashRefunds, 2),
            'cashExpenses' => round($cashExpenses, 2),
            'cashIn' => round($cashIn, 2),
            'cashOut' => round($cashOut, 2),
            'cashPayouts' => round($cashOut, 2),
            'nonCashSales' => round($nonCashSales, 2),
            'totalSales' => $totalSales,
            'expected' => $expected,
            'actual' => $actual,
            'variance' => $variance,
            'status' => $status,
            'posSessionId' => $session?->id,
            'paymentBreakdown' => $this->paymentBreakdown($outletId, $session),
            'limitations' => $limitations,
        ];
    }

    private function resolveSession(int $outletId, ?int $posSessionId): ?PosSession
    {
        if ($posSessionId !== null && $posSessionId > 0) {
            return PosSession::query()->whereKey($posSessionId)->where('outlet_id', $outletId)->first();
        }

        return PosSession::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'open')
            ->latest('id')
            ->first()
            ?? PosSession::query()
                ->where('outlet_id', $outletId)
                ->where('status', 'closed')
                ->latest('closed_at')
                ->first();
    }

    private function sumCashSales(int $outletId, ?PosSession $session): float
    {
        return $this->sumPayments($outletId, $session, self::CASH_METHODS, positiveOnly: true);
    }

    private function sumCashRefunds(int $outletId, ?PosSession $session): float
    {
        return abs($this->sumPayments($outletId, $session, self::CASH_METHODS, positiveOnly: false));
    }

    private function sumNonCashSales(int $outletId, ?PosSession $session): float
    {
        $query = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.outlet_id', $outletId)
            ->whereNotIn('payments.method', self::CASH_METHODS)
            ->where('payments.amount', '>', 0)
            ->where(function ($q): void {
                $q->whereNull('payments.status')->orWhere('payments.status', '!=', 'void');
            });

        $this->applySessionWindow($query, $session, 'payments.paid_at');

        return (float) $query->sum('payments.amount');
    }

    private function sumCashExpenses(int $outletId, ?PosSession $session): float
    {
        if (! $this->hasCashMovementTable('pos_session_cash_expenses')) {
            return 0.0;
        }

        $query = DB::table('pos_session_cash_expenses')
            ->where('outlet_id', $outletId);

        if ($session !== null) {
            $query->where('pos_session_id', $session->id);
        }

        return (float) $query->sum('amount');
    }

    private function sumCashIn(int $outletId, ?PosSession $session): float
    {
        return $this->sumCashMovements($outletId, $session, 'in');
    }

    private function sumCashOut(int $outletId, ?PosSession $session): float
    {
        return $this->sumCashMovements($outletId, $session, 'out');
    }

    private function sumCashMovements(int $outletId, ?PosSession $session, string $direction): float
    {
        if (! $this->hasCashMovementTable('pos_session_cash_movements')) {
            return 0.0;
        }

        $query = DB::table('pos_session_cash_movements')
            ->where('outlet_id', $outletId)
            ->where('direction', $direction);

        if ($session !== null) {
            $query->where('pos_session_id', $session->id);
        }

        return (float) $query->sum('amount');
    }

    /**
     * @param  list<string>  $methods
     */
    private function sumPayments(int $outletId, ?PosSession $session, array $methods, bool $positiveOnly): float
    {
        $query = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.outlet_id', $outletId)
            ->whereIn('payments.method', $methods)
            ->where(function ($q): void {
                $q->whereNull('payments.status')->orWhere('payments.status', '!=', 'void');
            });

        if ($positiveOnly) {
            $query->where('payments.amount', '>', 0);
        } else {
            $query->where('payments.amount', '<', 0);
        }

        $this->applySessionWindow($query, $session, 'payments.paid_at');

        return (float) $query->sum('payments.amount');
    }

    /**
     * @return list<array{method: string, amount: float}>
     */
    private function paymentBreakdown(int $outletId, ?PosSession $session): array
    {
        $query = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.outlet_id', $outletId)
            ->where('payments.amount', '>', 0)
            ->where(function ($q): void {
                $q->whereNull('payments.status')->orWhere('payments.status', '!=', 'void');
            });

        $this->applySessionWindow($query, $session, 'payments.paid_at');

        return $query
            ->select('payments.method', DB::raw('SUM(payments.amount) as total'))
            ->groupBy('payments.method')
            ->get()
            ->map(fn ($row): array => [
                'method' => (string) $row->method,
                'amount' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function applySessionWindow($query, ?PosSession $session, string $column): void
    {
        if ($session !== null && $session->opened_at !== null) {
            $query->where($column, '>=', $session->opened_at);
            if ($session->closed_at !== null) {
                $query->where($column, '<=', $session->closed_at);
            }
        }
    }

    private function hasCashMovementTable(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
