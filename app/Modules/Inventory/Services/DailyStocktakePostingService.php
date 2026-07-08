<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\DailyStocktakeSession;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class DailyStocktakePostingService
{
    public function __construct(
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
        private readonly InventoryValuationService $inventoryValuationService,
        private readonly InventoryCostService $inventoryCostService,
        private readonly InventoryCostingPolicyService $costingPolicyService,
        private readonly InventoryConsumptionPostingService $consumptionPostingService,
        private readonly JournalPostingService $journalPostingService,
        private readonly PosAuditLogService $auditLogService,
    ) {}

    public function approveAndPost(int $sessionId, ?User $actor = null): DailyStocktakeSession
    {
        return DB::transaction(function () use ($sessionId, $actor): DailyStocktakeSession {
            /** @var DailyStocktakeSession $session */
            $session = DailyStocktakeSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            abort_if(
                $session->status !== DailyStocktakeSession::STATUS_PENDING_APPROVAL,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Stocktake must be pending approval before posting.',
            );

            $session->load('lines');
            $outletId = (int) $session->outlet_id;
            $businessDate = $session->business_date->toDateString();
            $costMethod = $this->costingPolicyService->getMethod();
            $sourceId = (string) $session->id;

            foreach ($session->lines as $line) {
                $ingredientId = (int) $line->ingredient_id;
                $unitCost = (float) $line->unit_cost;
                if ($unitCost <= 0) {
                    $unitCost = $this->inventoryCostService->resolveUnitCost($ingredientId, $outletId);
                }

                $overnight = (float) $line->overnight_variance_qty;
                if ($overnight > 0) {
                    $this->postVarianceMovement(
                        $outletId,
                        $ingredientId,
                        $overnight,
                        'waste',
                        $costMethod,
                        $unitCost,
                        $sourceId,
                        'overnight_variance',
                        $actor,
                    );
                }

                $operational = (float) $line->operational_variance_qty;
                if ($operational > 0) {
                    $this->postVarianceMovement(
                        $outletId,
                        $ingredientId,
                        $operational,
                        'waste',
                        $costMethod,
                        $unitCost,
                        $sourceId,
                        'operational_variance',
                        $actor,
                    );
                } elseif ($operational < 0) {
                    $this->postVarianceMovement(
                        $outletId,
                        $ingredientId,
                        abs($operational),
                        'adjustment',
                        $costMethod,
                        $unitCost,
                        $sourceId,
                        'operational_surplus',
                        $actor,
                    );
                }
            }

            $consumptionResult = $this->consumptionPostingService->processOutletForBusinessDate(
                $outletId,
                $businessDate,
                'daily_stocktake',
            );

            $session->update([
                'status' => DailyStocktakeSession::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $actor?->id,
                'approved_by' => $actor?->id,
            ]);

            $this->auditLogService->log(
                'inventory.stocktake_posted',
                'daily_stocktake_session',
                (int) $session->id,
                $outletId,
                $actor,
                [
                    'businessDate' => $businessDate,
                    'consumptionProcessed' => $consumptionResult['processed'] ?? 0,
                    'totalCogs' => $consumptionResult['totalCogs'] ?? 0,
                ],
            );

            return DailyStocktakeSession::query()
                ->with('lines.ingredient:id,name,unit')
                ->findOrFail($session->id);
        });
    }

    private function postVarianceMovement(
        int $outletId,
        int $ingredientId,
        float $qty,
        string $type,
        string $costMethod,
        float $unitCost,
        string $sourceId,
        string $event,
        ?User $actor,
    ): void {
        if ($qty <= 0) {
            return;
        }

        if ($type === 'waste') {
            $resolvedCost = $this->inventoryValuationService->recordConsumption($ingredientId, $outletId, $qty, $actor);
            if ($resolvedCost > 0) {
                $unitCost = $resolvedCost;
            }
        }

        $movement = $this->ingredientOutletStockLedger->apply(
            $outletId,
            $ingredientId,
            $type,
            $qty,
            'daily_stocktake',
            $sourceId.'-'.$event.'-'.$ingredientId,
            [
                'cost_method' => $costMethod,
                'unit_cost' => $unitCost,
                'event' => $event,
                'stocktake_session_id' => (int) $sourceId,
            ],
        );

        if ($type === 'adjustment') {
            $this->inventoryValuationService->recordPurchase(
                $ingredientId,
                $outletId,
                $qty,
                $unitCost,
                null,
                $actor,
                (int) $movement->id,
            );
        }

        $tenantId = 1;
        $this->journalPostingService->postForInventoryMovement(
            $type,
            (int) $movement->id,
            $tenantId,
            $outletId,
            (float) ($movement->total_cost ?? 0),
        );
    }
}
