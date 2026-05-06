<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Modules\Inventory\DTOs\CreateIngredientData;
use App\Modules\Inventory\DTOs\CreateStockMovementData;
use App\Modules\Inventory\Repositories\IngredientRepositoryInterface;
use App\Modules\Inventory\Repositories\StockMovementRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InventoryService
{
    public function __construct(
        private readonly IngredientRepositoryInterface $ingredientRepository,
        private readonly StockMovementRepositoryInterface $stockMovementRepository,
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
    ) {}

    public function listIngredients(int $tenantId, int $perPage = 20)
    {
        return $this->ingredientRepository->paginateByTenant($tenantId, $perPage);
    }

    public function createIngredient(CreateIngredientData $data)
    {
        return DB::transaction(function () use ($data) {
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
                    $this->ingredientOutletStockLedger->apply(
                        (int) $outletId,
                        (int) $ingredient->id,
                        'purchase',
                        (float) $data->stock,
                        'ingredient_create',
                        (string) $ingredient->id
                    );
                } else {
                    InventoryStock::query()->firstOrCreate(
                        ['ingredient_id' => $ingredient->id, 'outlet_id' => $outletId],
                        ['stock' => 0]
                    );
                }
            }

            return $ingredient;
        });
    }

    public function updateIngredient(int $id, array $attributes)
    {
        $ingredient = $this->ingredientRepository->findById($id);
        if ($ingredient === null) {
            return null;
        }

        $this->ingredientRepository->update($ingredient, $attributes);

        return $ingredient->refresh();
    }

    public function deleteIngredient(int $id): bool
    {
        $ingredient = $this->ingredientRepository->findById($id);
        if ($ingredient === null) {
            return false;
        }

        return $this->ingredientRepository->delete($ingredient);
    }

    public function listStockMovements(int $tenantId, int $perPage = 20, ?int $outletId = null)
    {
        return $this->stockMovementRepository->paginateByTenant($tenantId, $perPage, $outletId);
    }

    public function addStockMovement(CreateStockMovementData $data)
    {
        $ingredient = $this->ingredientRepository->findById($data->inventoryItemId);
        abort_if($ingredient === null, Response::HTTP_NOT_FOUND, 'Ingredient not found');

        return $this->ingredientOutletStockLedger->apply(
            $data->outletId,
            $data->inventoryItemId,
            $data->type,
            $data->quantity,
            $data->sourceType,
            $data->sourceId,
        );
    }
}
