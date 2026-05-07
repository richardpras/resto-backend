<?php

namespace App\Modules\Inventory\Repositories;

use App\Models\Modules\Inventory\Domain\Ingredient;

interface IngredientRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, ?int $outletId = null, ?array $allowedOutletIds = null);

    public function findById(int $id): ?Ingredient;

    public function create(array $attributes): Ingredient;

    public function update(Ingredient $ingredient, array $attributes): bool;

    public function delete(Ingredient $ingredient): bool;

    public function updateStock(Ingredient $ingredient, float $newStock): bool;
}
