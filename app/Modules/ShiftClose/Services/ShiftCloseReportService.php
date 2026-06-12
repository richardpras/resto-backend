<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShiftCloseReportService
{
    /** @return array<string, mixed> */
    public function build(int $runId, int $outletId): array
    {
        $run = ShiftCloseRun::query()
            ->whereKey($runId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($run === null) {
            throw new ModelNotFoundException('Shift close run not found.');
        }

        $outlet = Outlet::query()->find($outletId);
        $preflight = is_array($run->preflight_snapshot) ? $run->preflight_snapshot : [];
        $result = is_array($run->result_snapshot) ? $run->result_snapshot : [];
        $cash = is_array($result['cash'] ?? null) ? $result['cash'] : [];
        $inventory = is_array($result['inventory'] ?? null) ? $result['inventory'] : [];
        $accounting = is_array($result['accounting'] ?? null) ? $result['accounting'] : [];
        $metadata = is_array($run->metadata) ? $run->metadata : [];

        return [
            'format' => 'json',
            'pdfAvailable' => false,
            'runId' => $run->id,
            'outlet' => [
                'id' => $outletId,
                'name' => $outlet?->name,
                'code' => $outlet?->code,
            ],
            'shiftDate' => $run->shift_date?->toDateString() ?? $run->started_at?->toDateString(),
            'startedAt' => $run->started_at?->toISOString(),
            'completedAt' => $run->completed_at?->toISOString(),
            'status' => $run->status,
            'sales' => [
                'total' => (float) ($run->sales_amount ?? $result['totalSales'] ?? 0),
                'cash' => (float) ($run->cash_sales ?? $cash['cashSales'] ?? 0),
                'nonCash' => (float) ($run->non_cash_sales ?? 0),
            ],
            'paymentBreakdown' => $metadata['paymentBreakdown'] ?? [],
            'cashReconciliation' => [
                'openingCash' => (float) ($run->opening_cash ?? $cash['openingCash'] ?? 0),
                'cashSales' => (float) ($run->cash_sales ?? $cash['cashSales'] ?? 0),
                'cashRefunds' => (float) ($run->cash_refunds ?? $cash['cashRefunds'] ?? 0),
                'cashExpenses' => (float) ($run->cash_expenses ?? $cash['cashExpenses'] ?? 0),
                'cashIn' => (float) ($run->cash_in ?? $cash['cashIn'] ?? 0),
                'cashOut' => (float) ($run->cash_out ?? $cash['cashOut'] ?? 0),
                'expectedCash' => (float) ($run->expected_cash ?? $cash['expected'] ?? 0),
                'actualCash' => $run->actual_cash ?? $cash['actual'] ?? null,
                'variance' => $run->cash_variance ?? $cash['variance'] ?? null,
                'status' => $cash['status'] ?? 'unknown',
                'limitations' => $cash['limitations'] ?? [],
            ],
            'warnings' => [
                'openBills' => (int) ($run->open_bill_count ?? 0),
                'openPosSessions' => (int) ($run->open_pos_session_count ?? 0),
                'qrOrders' => [
                    'pending' => (int) ($run->pending_qr_count ?? 0),
                    'underReview' => (int) ($run->under_review_qr_count ?? 0),
                    'linkedUnpaidBills' => (int) ($run->linked_unpaid_qr_bill_count ?? 0),
                ],
            ],
            'inventoryPosting' => [
                'processed' => (int) ($inventory['processed'] ?? 0),
                'failed' => (int) ($inventory['failed'] ?? 0),
                'varianceDetected' => (int) ($run->inventory_variance ?? $inventory['varianceDetected'] ?? 0),
            ],
            'accountingPosting' => [
                'skipped' => (bool) ($accounting['skipped'] ?? false),
                'orderCount' => (int) ($accounting['orderCount'] ?? 0),
                'journalId' => $accounting['journalId'] ?? null,
                'totalSales' => (float) ($accounting['totalSales'] ?? 0),
                'totalCogs' => (float) ($accounting['totalCogs'] ?? 0),
            ],
            'cashVariance' => $run->cash_variance,
            'auditReference' => [
                'entityType' => 'shift_close',
                'entityId' => $run->outlet_id,
                'runId' => $run->id,
                'preflightWarnings' => $preflight['warnings'] ?? [],
            ],
        ];
    }
}
