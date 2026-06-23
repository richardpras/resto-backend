<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\Payment;
use App\Modules\Accounting\Services\AccountingSettingsService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\GiftCards\Services\GiftCardAccountingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftCloseAccountingProcessor
{
    public function __construct(
        private readonly AccountingSettingsService $accountingSettingsService,
        private readonly JournalPostingService $journalPostingService,
        private readonly ShiftCloseAccountingGuard $accountingGuard,
    ) {}

    /** @return array<string, mixed> */
    public function process(?int $tenantId, int $outletId, float $deferredCogsPosted): array
    {
        if ($this->accountingSettingsService->isRealtimeMode($tenantId, $outletId)) {
            return [
                'skipped' => true,
                'reason' => 'Revenue posting mode is realtime; shift close revenue posting skipped.',
                'orderCount' => 0,
                'totalSales' => 0.0,
                'totalCogs' => 0.0,
                'journalId' => null,
            ];
        }

        return DB::transaction(function () use ($tenantId, $outletId, $deferredCogsPosted): array {
            $orders = Order::query()
                ->where('payment_status', 'paid')
                ->where('is_posted', false)
                ->where('outlet_id', $outletId)
                ->when($tenantId !== null && $tenantId > 0, fn ($q) => $q->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->with('items:id,order_id,item_id,qty')
                ->get(['id', 'code', 'tenant_id', 'outlet_id', 'total', 'paid_total']);

            if ($orders->isEmpty()) {
                return [
                    'skipped' => false,
                    'orderCount' => 0,
                    'totalSales' => 0.0,
                    'totalCogs' => 0.0,
                    'journalId' => null,
                ];
            }

            $totalCashSales = (float) $orders->sum(fn (Order $order): float => (float) $order->paid_total);
            $orderIds = $orders->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $paymentAmountsByMethod = $this->aggregatePaymentAmountsByMethod($orderIds);
            $totalCogs = $this->accountingGuard->resolveCogsForShiftCloseJournal(
                $outletId,
                $orders,
                $deferredCogsPosted,
            );
            $journalOutletId = $this->resolveJournalOutletId($outletId, $orders);
            $giftCardComposition = app(GiftCardAccountingService::class)
                ->compositionFromOrderIds($orderIds, $journalOutletId, settledOnly: true);
            $batchKey = now()->format('YmdHis').'-'.$journalOutletId;

            $journal = $this->journalPostingService->postForShiftClose(
                (int) ($tenantId ?? 0),
                $journalOutletId,
                round($totalCashSales, 2),
                $totalCogs,
                $batchKey,
                $giftCardComposition,
                $paymentAmountsByMethod,
            );

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'accounts' => ['Shift close posting failed. Check accounting health for missing mappings or period lock.'],
                ]);
            }

            Order::query()
                ->whereIn('id', $orders->pluck('id')->all())
                ->where('is_posted', false)
                ->update(['is_posted' => true]);

            return [
                'skipped' => false,
                'orderCount' => $orders->count(),
                'totalSales' => round($totalCashSales + $giftCardComposition->total(), 2),
                'totalCogs' => $totalCogs,
                'journalId' => (string) $journal->id,
                'batchKey' => $batchKey,
                'cogsGuardApplied' => $deferredCogsPosted > 0,
            ];
        });
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function resolveJournalOutletId(int $outletId, Collection $orders): int
    {
        $distinct = $orders->pluck('outlet_id')
            ->filter(fn (?int $v): bool => $v !== null && $v > 0)
            ->unique()
            ->values();

        return $distinct->count() === 1 ? (int) $distinct->first() : $outletId;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<string, float>
     */
    private function aggregatePaymentAmountsByMethod(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = Payment::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', '!=', 'void')
            ->get(['method', 'amount']);

        $amounts = [];
        foreach ($rows as $payment) {
            $method = strtolower(trim((string) $payment->method));
            if ($method === '') {
                $method = 'cash';
            }
            $amounts[$method] = round(($amounts[$method] ?? 0) + (float) $payment->amount, 2);
        }

        return $amounts;
    }
}
