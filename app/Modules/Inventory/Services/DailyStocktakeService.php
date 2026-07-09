<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\DailyStocktakeLine;
use App\Models\Modules\Inventory\Domain\DailyStocktakeSession;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class DailyStocktakeService
{
    public function __construct(
        private readonly DailyStocktakeTheoreticalUsageService $theoreticalUsageService,
        private readonly DailyStocktakePurchasesService $purchasesService,
        private readonly InventoryCostService $inventoryCostService,
    ) {}

    public function getOrCreateSession(int $outletId, string $businessDate, ?User $actor = null): DailyStocktakeSession
    {
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        $date = Carbon::parse($businessDate)->toDateString();

        $existing = DailyStocktakeSession::query()
            ->where('outlet_id', $outletId)
            ->whereDate('business_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing->load('lines.ingredient:id,name,unit');
        }

        return DB::transaction(function () use ($outletId, $date): DailyStocktakeSession {
            $session = DailyStocktakeSession::query()->create([
                'outlet_id' => $outletId,
                'business_date' => $date,
                'status' => DailyStocktakeSession::STATUS_DRAFT,
            ]);

            $this->seedLinesForSession($session);

            return $session->fresh(['lines.ingredient:id,name,unit']);
        });
    }

    /** @param array<int, array{ingredientId: int, openingQty: float}> $lines */
    public function saveOpening(int $sessionId, array $lines, ?User $actor = null): DailyStocktakeSession
    {
        return DB::transaction(function () use ($sessionId, $lines): DailyStocktakeSession {
            $session = $this->lockEditableSession($sessionId);
            $this->applyLineInputs($session, $lines, 'opening_qty');
            $session->update(['opening_submitted_at' => now()]);

            return $this->buildPreview($session->id);
        });
    }

    /** @param array<int, array{ingredientId: int, closingQty: float}> $lines */
    public function saveClosing(int $sessionId, array $lines, ?User $actor = null): DailyStocktakeSession
    {
        return DB::transaction(function () use ($sessionId, $lines): DailyStocktakeSession {
            $session = $this->lockEditableSession($sessionId);
            abort_if(
                $session->opening_submitted_at === null,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Opening count must be submitted before closing count.',
            );
            $this->applyLineInputs($session, $lines, 'closing_qty');
            $session->update(['closing_submitted_at' => now()]);

            return $this->buildPreview($session->id);
        });
    }

    public function buildPreview(int $sessionId): DailyStocktakeSession
    {
        return DB::transaction(function () use ($sessionId): DailyStocktakeSession {
            /** @var DailyStocktakeSession $session */
            $session = DailyStocktakeSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $session->load('lines');

            $outletId = (int) $session->outlet_id;
            $businessDate = $session->business_date->toDateString();
            $purchases = $this->purchasesService->purchasesForBusinessDate($outletId, $businessDate);
            $theoretical = $this->theoreticalUsageService->usageForBusinessDate($outletId, $businessDate);

            foreach ($session->lines as $line) {
                $ingredientId = (int) $line->ingredient_id;
                $opening = (float) ($line->opening_qty ?? 0);
                $closing = $line->closing_qty !== null ? (float) $line->closing_qty : null;
                $purchasesQty = (float) ($purchases[$ingredientId] ?? 0);
                $theoreticalQty = (float) ($theoretical[$ingredientId] ?? 0);
                $overnight = max(0, round((float) $line->previous_closing_qty - $opening, 4));

                $operational = 0.0;
                if ($closing !== null) {
                    $actualUsage = $opening + $purchasesQty - $closing;
                    $operational = round($actualUsage - $theoreticalQty - $overnight, 4);
                }

                $line->update([
                    'purchases_qty' => $purchasesQty,
                    'theoretical_usage_qty' => $theoreticalQty,
                    'overnight_variance_qty' => $overnight,
                    'operational_variance_qty' => $operational,
                    'unit_cost' => $this->inventoryCostService->resolveUnitCost($ingredientId, $outletId),
                ]);
            }

            return $session->fresh(['lines.ingredient:id,name,unit']);
        });
    }

    public function submitForApproval(int $sessionId, ?User $actor = null): DailyStocktakeSession
    {
        return DB::transaction(function () use ($sessionId, $actor): DailyStocktakeSession {
            $session = $this->lockEditableSession($sessionId);
            abort_if(
                $session->closing_submitted_at === null,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Closing count must be submitted before approval.',
            );
            $this->buildPreview($sessionId);
            $session->update(['status' => DailyStocktakeSession::STATUS_PENDING_APPROVAL]);

            return $session->fresh(['lines.ingredient:id,name,unit']);
        });
    }

    public function approveAndPost(int $sessionId, ?User $actor = null): DailyStocktakeSession
    {
        return app(DailyStocktakePostingService::class)->approveAndPost($sessionId, $actor);
    }

    public function cancel(int $sessionId, ?User $actor = null): DailyStocktakeSession
    {
        $session = DailyStocktakeSession::query()->findOrFail($sessionId);
        abort_if(
            $session->status === DailyStocktakeSession::STATUS_POSTED,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Posted stocktake cannot be cancelled.',
        );
        $session->update(['status' => DailyStocktakeSession::STATUS_CANCELLED]);

        return $session->fresh(['lines.ingredient:id,name,unit']);
    }

    /** @return Collection<int, DailyStocktakeSession> */
    public function listSessions(int $outletId, ?string $from = null, ?string $to = null): Collection
    {
        return DailyStocktakeSession::query()
            ->where('outlet_id', $outletId)
            ->when($from !== null, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('business_date', '<=', $to))
            ->orderByDesc('business_date')
            ->limit(60)
            ->get();
    }

    public function findSession(int $sessionId): DailyStocktakeSession
    {
        return DailyStocktakeSession::query()
            ->with('lines.ingredient:id,name,unit')
            ->findOrFail($sessionId);
    }

    public function hasPostedSessionForDate(int $outletId, string $businessDate): bool
    {
        return DailyStocktakeSession::query()
            ->where('outlet_id', $outletId)
            ->whereDate('business_date', $businessDate)
            ->where('status', DailyStocktakeSession::STATUS_POSTED)
            ->exists();
    }

    private function seedLinesForSession(DailyStocktakeSession $session): void
    {
        $outletId = (int) $session->outlet_id;
        $businessDate = $session->business_date->toDateString();
        $previousClosing = $this->previousClosingQuantities($outletId, $businessDate);

        $ingredients = Ingredient::query()
            ->where('type', 'ingredient')
            ->where(function ($q) use ($outletId): void {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->orderBy('name')
            ->get(['id']);

        foreach ($ingredients as $ingredient) {
            $ingredientId = (int) $ingredient->id;
            $previousQty = (float) ($previousClosing[$ingredientId] ?? $this->resolveInventoryStockQty($outletId, $ingredientId));
            DailyStocktakeLine::query()->create([
                'session_id' => $session->id,
                'ingredient_id' => $ingredientId,
                'previous_closing_qty' => $previousQty,
                'opening_qty' => $previousQty,
            ]);
        }
    }

    private function resolveInventoryStockQty(int $outletId, int $ingredientId): float
    {
        $stock = DB::table('inventory_stocks')
            ->where('outlet_id', $outletId)
            ->where('ingredient_id', $ingredientId)
            ->value('stock');

        return (float) ($stock ?? 0);
    }

    /** @return array<int, float> */
    private function previousClosingQuantities(int $outletId, string $businessDate): array
    {
        $previousSession = DailyStocktakeSession::query()
            ->where('outlet_id', $outletId)
            ->whereDate('business_date', '<', $businessDate)
            ->where('status', DailyStocktakeSession::STATUS_POSTED)
            ->orderByDesc('business_date')
            ->first();

        if ($previousSession === null) {
            return [];
        }

        $previousSession->load('lines');

        $result = [];
        foreach ($previousSession->lines as $line) {
            if ($line->closing_qty !== null) {
                $result[(int) $line->ingredient_id] = (float) $line->closing_qty;
            }
        }

        return $result;
    }

    private function lockEditableSession(int $sessionId): DailyStocktakeSession
    {
        /** @var DailyStocktakeSession $session */
        $session = DailyStocktakeSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
        abort_if(
            $session->status === DailyStocktakeSession::STATUS_POSTED,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Posted stocktake is read-only.',
        );
        abort_if(
            $session->status === DailyStocktakeSession::STATUS_CANCELLED,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Cancelled stocktake cannot be edited.',
        );

        return $session;
    }

    /** @param array<int, array{ingredientId: int, openingQty?: float, closingQty?: float}> $lines */
    private function applyLineInputs(DailyStocktakeSession $session, array $lines, string $field): void
    {
        foreach ($lines as $input) {
            $ingredientId = (int) ($input['ingredientId'] ?? 0);
            $qty = (float) ($input[$field === 'opening_qty' ? 'openingQty' : 'closingQty'] ?? 0);
            abort_if($ingredientId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'ingredientId is required.');
            abort_if($qty < 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Quantity cannot be negative.');

            DailyStocktakeLine::query()
                ->where('session_id', $session->id)
                ->where('ingredient_id', $ingredientId)
                ->update([$field => $qty]);
        }
    }
}
