<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\DailyStocktakeLine;
use App\Models\Modules\Inventory\Domain\DailyStocktakeSession;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DailyStocktakeService
{
    public function __construct(
        private readonly InventoryCostService $inventoryCostService,
        private readonly IngredientOutletStockLedger $stockLedger,
        private readonly InventoryValuationService $valuationService,
        private readonly InventoryConsumptionPostingService $consumptionPostingService,
    ) {}

    /** @return Collection<int, DailyStocktakeSession> */
    public function listSessions(int $outletId, ?string $from = null, ?string $to = null): Collection
    {
        return DailyStocktakeSession::query()
            ->with(['lines.ingredient'])
            ->where('outlet_id', $outletId)
            ->when($from !== null && $from !== '', fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to !== null && $to !== '', fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();
    }

    public function createSession(int $outletId, string $businessDate, ?User $actor = null): DailyStocktakeSession
    {
        $existing = DailyStocktakeSession::query()
            ->where('outlet_id', $outletId)
            ->whereDate('business_date', $businessDate)
            ->where('status', '!=', DailyStocktakeSession::STATUS_CANCELLED)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'businessDate' => ['A stocktake session already exists for this outlet and date.'],
            ]);
        }

        return DB::transaction(function () use ($outletId, $businessDate, $actor): DailyStocktakeSession {
            $session = DailyStocktakeSession::query()->create([
                'outlet_id' => $outletId,
                'business_date' => $businessDate,
                'status' => DailyStocktakeSession::STATUS_DRAFT,
                'created_by_user_id' => $actor?->id,
            ]);

            $ingredients = Ingredient::query()
                ->where('outlet_id', $outletId)
                ->where('type', 'ingredient')
                ->orderBy('name')
                ->get();

            foreach ($ingredients as $ingredient) {
                $previousClosing = $this->resolvePreviousClosingQty($outletId, (int) $ingredient->id, $businessDate);
                $purchasesQty = $this->sumMovementQty($outletId, (int) $ingredient->id, $businessDate, 'purchase');
                $unitCost = $this->inventoryCostService->resolveUnitCost((int) $ingredient->id, $outletId);

                DailyStocktakeLine::query()->create([
                    'session_id' => $session->id,
                    'ingredient_id' => $ingredient->id,
                    'previous_closing_qty' => $previousClosing,
                    'opening_qty' => $previousClosing,
                    'overnight_variance_qty' => 0,
                    'purchases_qty' => $purchasesQty,
                    'unit_cost' => $unitCost,
                    'theoretical_usage_qty' => $this->sumMovementQty($outletId, (int) $ingredient->id, $businessDate, 'sale'),
                ]);
            }

            return $this->loadSession((int) $session->id);
        });
    }

    public function loadSession(int $sessionId): DailyStocktakeSession
    {
        $session = DailyStocktakeSession::query()
            ->with(['lines.ingredient'])
            ->find($sessionId);

        abort_if($session === null, Response::HTTP_NOT_FOUND, 'Daily stocktake session not found.');

        return $session;
    }

    /**
     * @param  list<array{ingredientId:int, openingQty?:float}>  $lines
     */
    public function saveOpening(DailyStocktakeSession $session, array $lines): DailyStocktakeSession
    {
        $this->assertEditable($session);

        return DB::transaction(function () use ($session, $lines): DailyStocktakeSession {
            foreach ($lines as $row) {
                $line = $this->findLine($session, (int) $row['ingredientId']);
                if ($line === null || ! isset($row['openingQty'])) {
                    continue;
                }

                $openingQty = max(0, (float) $row['openingQty']);
                $line->opening_qty = $openingQty;
                $line->overnight_variance_qty = round($openingQty - (float) $line->previous_closing_qty, 4);
                $line->purchases_qty = $this->sumMovementQty(
                    (int) $session->outlet_id,
                    (int) $line->ingredient_id,
                    $session->business_date->toDateString(),
                    'purchase',
                );
                $line->theoretical_usage_qty = $this->sumMovementQty(
                    (int) $session->outlet_id,
                    (int) $line->ingredient_id,
                    $session->business_date->toDateString(),
                    'sale',
                );
                $this->recalculateOperationalVariance($line);
                $line->save();
            }

            $session->opening_submitted_at = now();
            $session->save();

            return $this->loadSession((int) $session->id);
        });
    }

    /**
     * @param  list<array{ingredientId:int, closingQty?:float}>  $lines
     */
    public function saveClosing(DailyStocktakeSession $session, array $lines): DailyStocktakeSession
    {
        $this->assertEditable($session);
        abort_if($session->opening_submitted_at === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Opening counts must be saved first.');

        return DB::transaction(function () use ($session, $lines): DailyStocktakeSession {
            foreach ($lines as $row) {
                $line = $this->findLine($session, (int) $row['ingredientId']);
                if ($line === null || ! isset($row['closingQty'])) {
                    continue;
                }

                $line->closing_qty = max(0, (float) $row['closingQty']);
                $line->theoretical_usage_qty = $this->sumMovementQty(
                    (int) $session->outlet_id,
                    (int) $line->ingredient_id,
                    $session->business_date->toDateString(),
                    'sale',
                );
                $this->recalculateOperationalVariance($line);
                $line->save();
            }

            $session->closing_submitted_at = now();
            $session->save();

            return $this->loadSession((int) $session->id);
        });
    }

    public function submit(DailyStocktakeSession $session): DailyStocktakeSession
    {
        $this->assertEditable($session);
        abort_if($session->closing_submitted_at === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Closing counts must be saved first.');

        $session->update(['status' => DailyStocktakeSession::STATUS_PENDING_APPROVAL]);

        return $this->loadSession((int) $session->id);
    }

    public function approve(DailyStocktakeSession $session, ?User $actor = null): DailyStocktakeSession
    {
        abort_unless(
            $session->status === DailyStocktakeSession::STATUS_PENDING_APPROVAL,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Only pending stocktake sessions can be approved.',
        );

        return DB::transaction(function () use ($session, $actor): DailyStocktakeSession {
            $session->load('lines');
            $outletId = (int) $session->outlet_id;
            $sessionKey = (string) $session->id;

            $this->consumptionPostingService->processOutlet($outletId, 'daily_stocktake');

            foreach ($session->lines as $line) {
                $this->postVarianceMovements($outletId, $line, $sessionKey);
            }

            $session->update([
                'status' => DailyStocktakeSession::STATUS_POSTED,
                'posted_at' => now(),
                'approved_by_user_id' => $actor?->id,
            ]);

            return $this->loadSession((int) $session->id);
        });
    }

    public function cancel(DailyStocktakeSession $session): DailyStocktakeSession
    {
        abort_if(
            $session->status === DailyStocktakeSession::STATUS_POSTED,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Posted stocktake sessions cannot be cancelled.',
        );

        $session->update(['status' => DailyStocktakeSession::STATUS_CANCELLED]);

        return $this->loadSession((int) $session->id);
    }

    private function assertEditable(DailyStocktakeSession $session): void
    {
        abort_unless(
            $session->status === DailyStocktakeSession::STATUS_DRAFT,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Only draft stocktake sessions can be edited.',
        );
    }

    private function findLine(DailyStocktakeSession $session, int $ingredientId): ?DailyStocktakeLine
    {
        return DailyStocktakeLine::query()
            ->where('session_id', $session->id)
            ->where('ingredient_id', $ingredientId)
            ->first();
    }

    private function resolvePreviousClosingQty(int $outletId, int $ingredientId, string $businessDate): float
    {
        $previousSessionId = DailyStocktakeSession::query()
            ->where('outlet_id', $outletId)
            ->where('status', DailyStocktakeSession::STATUS_POSTED)
            ->whereDate('business_date', '<', $businessDate)
            ->orderByDesc('business_date')
            ->value('id');

        if ($previousSessionId !== null) {
            $closing = DailyStocktakeLine::query()
                ->where('session_id', $previousSessionId)
                ->where('ingredient_id', $ingredientId)
                ->value('closing_qty');

            if ($closing !== null) {
                return (float) $closing;
            }
        }

        $stock = InventoryStock::query()
            ->where('outlet_id', $outletId)
            ->where('ingredient_id', $ingredientId)
            ->value('stock');

        return (float) ($stock ?? 0);
    }

    private function sumMovementQty(int $outletId, int $ingredientId, string $businessDate, string $type): float
    {
        return (float) StockMovement::query()
            ->where('outlet_id', $outletId)
            ->where('inventory_item_id', $ingredientId)
            ->where('type', $type)
            ->whereDate('created_at', $businessDate)
            ->sum('quantity');
    }

    private function recalculateOperationalVariance(DailyStocktakeLine $line): void
    {
        $opening = $line->opening_qty !== null ? (float) $line->opening_qty : 0.0;
        $purchases = (float) $line->purchases_qty;
        $theoretical = (float) $line->theoretical_usage_qty;
        $closing = $line->closing_qty !== null ? (float) $line->closing_qty : null;

        if ($closing === null) {
            $line->operational_variance_qty = 0;

            return;
        }

        $expectedClosing = $opening + $purchases - $theoretical;
        $line->operational_variance_qty = round($expectedClosing - $closing, 4);
    }

    private function postVarianceMovements(int $outletId, DailyStocktakeLine $line, string $sessionId): void
    {
        $unitCost = (float) $line->unit_cost;
        $ingredientId = (int) $line->ingredient_id;
        $payload = [
            'cost_method' => 'moving_average',
            'unit_cost' => $unitCost,
            'event' => 'daily_stocktake_variance',
        ];

        $overnight = (float) $line->overnight_variance_qty;
        if ($overnight > 0) {
            $this->stockLedger->apply(
                $outletId,
                $ingredientId,
                'waste',
                $overnight,
                'daily_stocktake',
                $sessionId,
                $payload,
                false,
            );
            $this->valuationService->recordConsumption($ingredientId, $outletId, $overnight);
        }

        $operational = (float) $line->operational_variance_qty;
        if ($operational > 0) {
            $this->stockLedger->apply(
                $outletId,
                $ingredientId,
                'waste',
                $operational,
                'daily_stocktake',
                $sessionId,
                $payload,
                false,
            );
            $this->valuationService->recordConsumption($ingredientId, $outletId, $operational);
        } elseif ($operational < 0) {
            $qty = abs($operational);
            $this->stockLedger->apply(
                $outletId,
                $ingredientId,
                'adjustment',
                $qty,
                'daily_stocktake',
                $sessionId,
                $payload,
                false,
            );
            $this->valuationService->recordPurchase($ingredientId, $outletId, $qty, $unitCost);
        }
    }
}
