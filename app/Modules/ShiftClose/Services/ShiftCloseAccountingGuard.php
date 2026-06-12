<?php

namespace App\Modules\ShiftClose\Services;

use App\Modules\Inventory\Services\InventorySalePolicyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShiftCloseAccountingGuard
{
    public function __construct(
        private readonly InventorySalePolicyService $inventorySalePolicyService,
    ) {}

    /**
     * Prevent double COGS when deferred consumption already posted inventory_consumption_posting journals.
     *
     * @param  Collection<int, \App\Models\Modules\Orders\Domain\Order>  $orders
     */
    public function resolveCogsForShiftCloseJournal(int $outletId, Collection $orders, float $deferredCogsPosted): float
    {
        $movementCogs = $this->sumMovementCogs($orders);

        if (! $this->inventorySalePolicyService->defersConsumption($outletId)) {
            return round($movementCogs, 2);
        }

        $alreadyConsumed = max($deferredCogsPosted, $this->sumDeferredConsumptionJournalCogs($outletId));

        return round(max(0.0, $movementCogs - $alreadyConsumed), 2);
    }

    /**
     * @param  Collection<int, \App\Models\Modules\Orders\Domain\Order>  $orders
     */
    private function sumMovementCogs(Collection $orders): float
    {
        $orderCodes = $orders->pluck('code')->filter()->values()->all();
        if ($orderCodes === []) {
            return 0.0;
        }

        return (float) DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->whereIn('source_id', $orderCodes)
            ->sum('total_cost');
    }

    private function sumDeferredConsumptionJournalCogs(int $outletId): float
    {
        return (float) DB::table('journal_entries')
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->where('journals.source_type', 'inventory_consumption_posting')
            ->where('journals.outlet_id', $outletId)
            ->where('accounts.subtype', 'cogs')
            ->where('journal_entries.debit', '>', 0)
            ->sum('journal_entries.debit');
    }
}
