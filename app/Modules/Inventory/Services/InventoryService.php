<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Inventory\DTOs\CreateIngredientData;
use App\Modules\Inventory\DTOs\CreateStockMovementData;
use App\Modules\Inventory\Events\InventoryCriticalAlertRaised;
use App\Modules\Inventory\Repositories\IngredientRepositoryInterface;
use App\Modules\Inventory\Repositories\StockMovementRepositoryInterface;
use App\Modules\Orders\Services\PosAuditLogService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class InventoryService
{
    public function __construct(
        private readonly IngredientRepositoryInterface $ingredientRepository,
        private readonly StockMovementRepositoryInterface $stockMovementRepository,
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
        private readonly InventoryValuationService $inventoryValuationService,
        private readonly InventoryCostService $inventoryCostService,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosAuditLogService $auditLogService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    public function listIngredients(int $tenantId, int $perPage = 20, ?int $outletId = null, ?User $actor = null)
    {
        $allowedOutletIds = $this->resolveAllowedOutletIds($actor);
        $this->assertOutletAllowed($outletId, $allowedOutletIds);

        return $this->ingredientRepository->paginateByTenant($tenantId, $perPage, $outletId, $allowedOutletIds);
    }

    public function createIngredient(CreateIngredientData $data, ?User $actor = null)
    {
        $allowedOutletIds = $this->resolveAllowedOutletIds($actor);
        $this->assertOutletAllowed($data->outletId, $allowedOutletIds);

        return DB::transaction(function () use ($data, $actor) {
            $ingredient = $this->ingredientRepository->create([
                'tenant_id' => $data->tenantId,
                'outlet_id' => $data->outletId,
                'name' => $data->name,
                'type' => $data->type,
                'unit' => $data->unit,
                'stock' => $data->stock,
                'min' => $data->min,
                'price' => $data->price,
                'notes' => $data->notes,
            ]);

            $outletId = $data->outletId;
            if ($outletId !== null && $outletId >= 1) {
                if ($data->stock > 0) {
                    $openingCost = (float) ($data->price ?? 0);
                    $this->ingredientOutletStockLedger->apply(
                        (int) $outletId,
                        (int) $ingredient->id,
                        'purchase',
                        (float) $data->stock,
                        'ingredient_create',
                        (string) $ingredient->id,
                        [
                            'cost_method' => 'moving_average',
                            'unit_cost' => $openingCost,
                            'event' => 'ingredient_opening_balance',
                        ]
                    );
                    $this->inventoryValuationService->recordPurchase(
                        (int) $ingredient->id,
                        (int) $outletId,
                        (float) $data->stock,
                        $openingCost,
                    );
                } else {
                    InventoryStock::query()->firstOrCreate(
                        ['ingredient_id' => $ingredient->id, 'outlet_id' => $outletId],
                        ['stock' => 0]
                    );
                }
            }

            $this->auditLogService->log(
                'inventory.ingredient.created',
                'ingredient',
                (int) $ingredient->id,
                $data->outletId,
                $actor,
                ['type' => $data->type]
            );

            return $ingredient;
        });
    }

    public function updateIngredient(int $id, array $attributes, ?User $actor = null)
    {
        $ingredient = $this->ingredientRepository->findById($id);
        if ($ingredient === null) {
            return null;
        }
        $this->assertOutletAllowed((int) $ingredient->outlet_id, $this->resolveAllowedOutletIds($actor));

        $this->ingredientRepository->update($ingredient, $attributes);
        $this->auditLogService->log(
            'inventory.ingredient.updated',
            'ingredient',
            (int) $ingredient->id,
            $ingredient->outlet_id !== null ? (int) $ingredient->outlet_id : null,
            $actor,
            ['fields' => array_keys($attributes)]
        );

        return $ingredient->refresh();
    }

    public function deleteIngredient(int $id, ?User $actor = null): bool
    {
        $ingredient = $this->ingredientRepository->findById($id);
        if ($ingredient === null) {
            return false;
        }
        $this->assertOutletAllowed((int) $ingredient->outlet_id, $this->resolveAllowedOutletIds($actor));

        $deleted = $this->ingredientRepository->delete($ingredient);
        if ($deleted) {
            $this->auditLogService->log(
                'inventory.ingredient.deleted',
                'ingredient',
                (int) $ingredient->id,
                $ingredient->outlet_id !== null ? (int) $ingredient->outlet_id : null,
                $actor
            );
        }

        return $deleted;
    }

    public function listStockMovements(int $tenantId, int $perPage = 20, ?int $outletId = null, ?User $actor = null)
    {
        $allowedOutletIds = $this->resolveAllowedOutletIds($actor);
        $this->assertOutletAllowed($outletId, $allowedOutletIds);

        return $this->stockMovementRepository->paginateByTenant($tenantId, $perPage, $outletId, $allowedOutletIds);
    }

    public function addStockMovement(CreateStockMovementData $data, ?User $actor = null)
    {
        $ingredient = $this->ingredientRepository->findById($data->inventoryItemId);
        abort_if($ingredient === null, Response::HTTP_NOT_FOUND, 'Ingredient not found');
        abort_if((string) $ingredient->type !== 'ingredient', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only ingredient can move stock');
        $this->assertOutletAllowed($data->outletId, $this->resolveAllowedOutletIds($actor));

        $unitCost = $this->inventoryCostService->resolveUnitCost($data->inventoryItemId, $data->outletId);
        $movement = $this->ingredientOutletStockLedger->apply(
            $data->outletId,
            $data->inventoryItemId,
            $data->type,
            $data->quantity,
            $data->sourceType,
            $data->sourceId,
            [
                'cost_method' => 'moving_average',
                'unit_cost' => $unitCost,
                'event' => $data->type === 'waste' ? 'inventory_waste' : 'inventory_adjustment',
            ],
        );

        if ($data->type === 'adjustment') {
            $this->inventoryValuationService->recordPurchase(
                $data->inventoryItemId,
                $data->outletId,
                $data->quantity,
                $unitCost,
            );
        } elseif ($data->type === 'waste') {
            $this->inventoryValuationService->recordConsumption(
                $data->inventoryItemId,
                $data->outletId,
                $data->quantity,
                $actor,
            );
        }

        $this->auditLogService->log(
            'inventory.movement.recorded',
            'stock_movement',
            (int) $movement->id,
            $data->outletId,
            $actor,
            ['type' => $data->type, 'sourceType' => $data->sourceType]
        );

        if (in_array($data->type, ['adjustment', 'waste'], true)) {
            $this->journalPostingService->postForInventoryMovement(
                $data->type,
                (int) $movement->id,
                (int) ($ingredient->tenant_id ?? 0),
                $data->outletId,
                (float) ($movement->total_cost ?? 0)
            );
        }
        $currentStock = (float) InventoryStock::query()
            ->where('outlet_id', $data->outletId)
            ->where('ingredient_id', $data->inventoryItemId)
            ->value('stock');
        $minimumStock = (float) ($ingredient->min ?? 0);
        if ($minimumStock > 0 && $currentStock <= $minimumStock) {
            event(new InventoryCriticalAlertRaised(
                outletId: (int) $data->outletId,
                ingredientId: (int) $ingredient->id,
                ingredientName: (string) $ingredient->name,
                currentStock: $currentStock,
                minimumStock: $minimumStock,
                movementId: (int) $movement->id,
                sequence: (int) $movement->id,
                aggregateUpdatedAtIso: $movement->updated_at?->toIso8601String()
            ));
        }

        return $movement;
    }

    /** @return list<int>|null */
    private function resolveAllowedOutletIds(?User $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        return $this->outletAccessResolver->allowedOutletIds($actor);
    }

    /** @param list<int>|null $allowedOutletIds */
    private function assertOutletAllowed(?int $outletId, ?array $allowedOutletIds): void
    {
        if ($allowedOutletIds === null || $outletId === null || $outletId < 1) {
            return;
        }
        if (! in_array($outletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }
}
