<?php

namespace Database\Seeders\Support;

use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Database\Seeders\Demo\DemoSeederContext;

/**
 * DEMO-DATA-SEEDER-03 — shift close history, drawer reconciliation snapshots.
 */
final class DemoShiftCloseReadinessPatch
{
    public static function apply(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            self::seedShiftCloseRuns($outlet);
        }
    }

    private static function seedShiftCloseRuns(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $session = DemoPatch03Support::openPosSession($outlet);
        $runner = User::query()->where('email', 'admin@restohub.demo')->first()
            ?? DemoPatch03Support::cashierForOutlet($outlet);
        $base = DemoPatch03Support::baseTime();

        $runs = [
            'COMPLETED' => [
                'status' => ShiftCloseRun::STATUS_COMPLETED,
                'severity' => 'healthy',
                'ready' => true,
                'sales' => 4250000,
                'cashSales' => 2100000,
                'nonCash' => 2150000,
                'opening' => 750000,
                'expected' => 2850000,
                'actual' => 2850000,
                'variance' => 0,
                'inventoryVariance' => 0,
                'openBills' => 0,
                'openSessions' => 0,
                'pendingQr' => 0,
                'underReviewQr' => 0,
                'linkedUnpaidQr' => 0,
                'pendingConsumption' => 0,
                'failedPosting' => 0,
            ],
            'WARNING' => [
                'status' => ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS,
                'severity' => 'warning',
                'ready' => false,
                'sales' => 3900000,
                'cashSales' => 1800000,
                'nonCash' => 2100000,
                'opening' => 750000,
                'expected' => 2520000,
                'actual' => 2485000,
                'variance' => -35000,
                'inventoryVariance' => 12000,
                'openBills' => 2,
                'openSessions' => 1,
                'pendingQr' => 1,
                'underReviewQr' => 1,
                'linkedUnpaidQr' => 2,
                'pendingConsumption' => 1,
                'failedPosting' => 1,
            ],
            'FAILED' => [
                'status' => ShiftCloseRun::STATUS_FAILED,
                'severity' => 'critical',
                'ready' => false,
                'sales' => 0,
                'cashSales' => 0,
                'nonCash' => 0,
                'opening' => 750000,
                'expected' => 750000,
                'actual' => 0,
                'variance' => -750000,
                'inventoryVariance' => 45000,
                'openBills' => 3,
                'openSessions' => 1,
                'pendingQr' => 2,
                'underReviewQr' => 1,
                'linkedUnpaidQr' => 1,
                'pendingConsumption' => 2,
                'failedPosting' => 2,
                'failure' => 'Accounting guard blocked duplicate COGS posting',
            ],
            'RUNNING' => [
                'status' => ShiftCloseRun::STATUS_RUNNING,
                'severity' => 'info',
                'ready' => false,
                'sales' => 1200000,
                'cashSales' => 600000,
                'nonCash' => 600000,
                'opening' => 750000,
                'expected' => 1350000,
                'actual' => null,
                'variance' => null,
                'inventoryVariance' => 0,
                'openBills' => 1,
                'openSessions' => 1,
                'pendingQr' => 1,
                'underReviewQr' => 1,
                'linkedUnpaidQr' => 1,
                'pendingConsumption' => 1,
                'failedPosting' => 1,
            ],
        ];

        foreach ($runs as $suffix => $row) {
            $demoRef = "{$prefix}-SHIFT-CLOSE-{$suffix}";
            $existing = ShiftCloseRun::query()
                ->where('outlet_id', $outlet->id)
                ->where('metadata->demoReference', $demoRef)
                ->first();

            $payload = [
                'tenant_id' => 1,
                'outlet_id' => $outlet->id,
                'shift_date' => $base->toDateString(),
                'pos_session_id' => $session->id,
                'run_by_user_id' => $runner?->id,
                'created_by_user_id' => $runner?->id,
                'status' => $row['status'],
                'severity' => $row['severity'],
                'ready' => $row['ready'],
                'sales_amount' => $row['sales'],
                'cash_sales' => $row['cashSales'],
                'non_cash_sales' => $row['nonCash'],
                'opening_cash' => $row['opening'],
                'cash_refunds' => $suffix === 'WARNING' ? 25000 : 0,
                'cash_expenses' => 0,
                'cash_in' => $suffix === 'COMPLETED' ? 50000 : 0,
                'cash_out' => $suffix === 'WARNING' ? 15000 : 0,
                'cash_expected' => $row['expected'],
                'cash_actual' => $row['actual'],
                'cash_variance' => $row['variance'],
                'expected_cash' => $row['expected'],
                'actual_cash' => $row['actual'],
                'inventory_variance' => $row['inventoryVariance'],
                'open_bill_count' => $row['openBills'],
                'open_pos_session_count' => $row['openSessions'],
                'pending_qr_count' => $row['pendingQr'],
                'under_review_qr_count' => $row['underReviewQr'],
                'linked_unpaid_qr_bill_count' => $row['linkedUnpaidQr'],
                'pending_consumption_count' => $row['pendingConsumption'],
                'failed_accounting_posting_count' => $row['failedPosting'],
                'failure_reason' => $row['failure'] ?? null,
                'started_at' => $base->addHours(8),
                'completed_at' => $row['status'] === ShiftCloseRun::STATUS_RUNNING ? null : $base->addHours(16),
                'metadata' => [
                    'demoReference' => $demoRef,
                    'demoPatch' => '03',
                    'drawerReconciliation' => [
                        'openingCash' => $row['opening'],
                        'cashSales' => $row['cashSales'],
                        'cashRefunds' => $suffix === 'WARNING' ? 25000 : 0,
                        'cashExpenses' => 0,
                        'cashIn' => $suffix === 'COMPLETED' ? 50000 : 0,
                        'cashOut' => $suffix === 'WARNING' ? 15000 : 0,
                        'expectedCash' => $row['expected'],
                        'actualCash' => $row['actual'],
                        'variance' => $row['variance'],
                    ],
                ],
                'preflight_snapshot' => [
                    'openPosSessions' => $row['openSessions'],
                    'openBills' => $row['openBills'],
                    'pendingQrOrders' => $row['pendingQr'] + $row['underReviewQr'],
                ],
                'result_snapshot' => $row['status'] === ShiftCloseRun::STATUS_RUNNING ? null : [
                    'status' => $row['status'],
                    'cashVariance' => $row['variance'],
                ],
            ];

            if ($existing !== null) {
                $existing->update($payload);
            } else {
                ShiftCloseRun::query()->create($payload);
            }
        }
    }
}
