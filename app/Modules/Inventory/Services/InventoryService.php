<?php

namespace App\Modules\Inventory\Services;

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
    ) {}

    public function listIngredients(int $tenantId, int $perPage = 20)
    {
        return $this->ingredientRepository->paginateByTenant($tenantId, $perPage);
    }

    public function createIngredient(CreateIngredientData $data)
    {
        return $this->ingredientRepository->create([
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

    public function listStockMovements(int $tenantId, int $perPage = 20)
    {
        return $this->stockMovementRepository->paginateByTenant($tenantId, $perPage);
    }

    public function addStockMovement(CreateStockMovementData $data)
    {
        return DB::transaction(function () use ($data) {
            $ingredient = $this->ingredientRepository->findById($data->inventoryItemId);
            abort_if($ingredient === null, Response::HTTP_NOT_FOUND, 'Ingredient not found');

            $sign = match ($data->type) {
                'purchase' => 1,
                'adjustment' => 1,
                'sale' => -1,
                'waste' => -1,
            };

            $nextStock = $sign === -1
                ? (float) $ingredient->stock - $data->quantity
                : (float) $ingredient->stock + $data->quantity;

            abort_if($nextStock < 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Stock cannot go below zero');

            $this->ingredientRepository->updateStock($ingredient, $nextStock);

            return $this->stockMovementRepository->create([
                'inventory_item_id' => $data->inventoryItemId,
                'type' => $data->type,
                'quantity' => $data->quantity,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
            ]);
        });
    }
}
