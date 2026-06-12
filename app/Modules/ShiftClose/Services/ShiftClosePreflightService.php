<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftClosePreflightService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly ShiftClosePolicyService $policyService,
        private readonly ShiftCloseCashReconciliationService $cashReconciliationService,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(?User $user, int $outletId, ?int $tenantId = null, ?int $posSessionId = null): array
    {
        if ($user !== null) {
            $this->assertOutletAllowed($user, $outletId);
        }

        $openPosSessions = $this->buildOpenPosSessions($outletId);
        $qrOrders = $this->buildQrOrderBreakdown($outletId);
        $drawerReconciliation = $this->cashReconciliationService->reconcile($outletId, null, $posSessionId);

        $checks = [
            'openBills' => $this->countOpenBills($outletId),
            'pendingQrOrders' => $qrOrders['pending'] + $qrOrders['underReview'] + $qrOrders['linkedUnpaidBills'],
            'pendingKitchenTickets' => $this->countPendingKitchenTickets($outletId),
            'failedPrintJobs' => $this->countPendingPrintJobs($outletId),
            'pendingConsumption' => $this->countPendingConsumption($outletId),
            'failedAccountingPostings' => $this->countFailedAccountingPostings($outletId),
            'openPosSession' => $openPosSessions['count'],
            'unpostedPaidOrders' => $this->countUnpostedPaidOrders($outletId, $tenantId),
        ];

        $warnings = [];
        $blocks = [];
        $policy = $this->policyService->openBillPolicy();

        if ($policy === 'block' && $checks['openBills'] > 0) {
            $blocks[] = 'open_bills';
        } elseif ($policy === 'warn' && $checks['openBills'] > 0) {
            $warnings[] = 'open_bills';
        }

        if ($openPosSessions['count'] > 0) {
            $warnings[] = 'open_pos_sessions';
        }

        if ($qrOrders['pending'] > 0 || $qrOrders['underReview'] > 0 || $qrOrders['linkedUnpaidBills'] > 0) {
            $warnings[] = 'qr_orders';
        }
        if ($checks['pendingKitchenTickets'] > 0) {
            $warnings[] = 'kitchen_tickets';
        }
        if ($checks['failedPrintJobs'] > 0) {
            $warnings[] = 'print_jobs';
        }
        if ($checks['pendingConsumption'] > 0) {
            $warnings[] = 'pending_consumption';
        }
        if ($checks['failedAccountingPostings'] > 0) {
            $warnings[] = 'accounting_failures';
        }

        $severity = 'healthy';
        $ready = true;

        if ($blocks !== []) {
            $severity = 'block';
            $ready = false;
        } elseif ($warnings !== []) {
            $severity = 'warning';
        }

        return [
            'ready' => $ready,
            'severity' => $severity,
            'checks' => $checks,
            'openPosSessions' => $openPosSessions,
            'qrOrders' => $qrOrders,
            'drawerReconciliation' => $drawerReconciliation,
            'warnings' => $warnings,
            'blocks' => $blocks,
            'policies' => [
                'openBillPolicy' => $policy,
                'openPosSessionPolicy' => 'warn',
            ],
        ];
    }

    /** @return array{count: int, severity: string, items: list<array<string, mixed>>} */
    private function buildOpenPosSessions(int $outletId): array
    {
        $sessions = PosSession::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'open')
            ->with('openedBy:id,name')
            ->orderBy('opened_at')
            ->get();

        $items = $sessions->map(fn (PosSession $session): array => [
            'id' => (int) $session->id,
            'cashierName' => $session->openedBy?->name ?? 'Unknown',
            'openedAt' => $session->opened_at?->toISOString(),
            'openingCash' => (float) $session->opening_cash,
        ])->values()->all();

        return [
            'count' => count($items),
            'severity' => count($items) > 0 ? 'warning' : 'healthy',
            'items' => $items,
        ];
    }

    /** @return array{pending: int, underReview: int, linkedUnpaidBills: int, severity: string} */
    private function buildQrOrderBreakdown(int $outletId): array
    {
        $pending = (int) QrOrderRequest::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'pending')
            ->count();

        $underReview = (int) QrOrderRequest::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'under_review')
            ->count();

        $linkedUnpaidBills = (int) QrOrderRequest::query()
            ->where('outlet_id', $outletId)
            ->whereNotNull('order_id')
            ->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.id', 'qr_order_requests.order_id')
                    ->whereIn('payment_status', ['unpaid', 'partial'])
                    ->where('status', '!=', 'cancelled');
            })
            ->count();

        $hasIssues = $pending > 0 || $underReview > 0 || $linkedUnpaidBills > 0;

        return [
            'pending' => $pending,
            'underReview' => $underReview,
            'linkedUnpaidBills' => $linkedUnpaidBills,
            'severity' => $hasIssues ? 'warning' : 'healthy',
        ];
    }

    private function countOpenBills(int $outletId): int
    {
        return (int) Order::query()
            ->where('outlet_id', $outletId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    private function countPendingKitchenTickets(int $outletId): int
    {
        return (int) DB::table('kitchen_tickets')
            ->where('outlet_id', $outletId)
            ->whereIn('status', ['queued', 'in_progress', 'ready'])
            ->count();
    }

    private function countPendingPrintJobs(int $outletId): int
    {
        return (int) DB::table('print_jobs')
            ->where('outlet_id', $outletId)
            ->whereIn('status', ['pending', 'failed'])
            ->count();
    }

    private function countPendingConsumption(int $outletId): int
    {
        return (int) InventoryConsumptionQueue::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', [
                InventoryConsumptionQueue::STATUS_PENDING,
                InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED,
            ])
            ->count();
    }

    private function countFailedAccountingPostings(int $outletId): int
    {
        return (int) AccountingPostingFailure::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'pending')
            ->count();
    }

    private function countUnpostedPaidOrders(int $outletId, ?int $tenantId): int
    {
        return (int) Order::query()
            ->where('outlet_id', $outletId)
            ->where('payment_status', 'paid')
            ->where('is_posted', false)
            ->when($tenantId !== null && $tenantId > 0, fn ($q) => $q->where('tenant_id', $tenantId))
            ->count();
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
