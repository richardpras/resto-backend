<?php

namespace App\Modules\Inventory\Repositories;

use App\Models\Modules\Inventory\Domain\Ingredient;

class EloquentIngredientRepository implements IngredientRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20)
    {
        return Ingredient::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Ingredient
    {
        return Ingredient::query()->find($id);
    }

    public function create(array $attributes): Ingredient
    {
        return Ingredient::query()->create($attributes);
    }

    public function update(Ingredient $ingredient, array $attributes): bool
    {
        return $ingredient->update($attributes);
    }

    public function delete(Ingredient $ingredient): bool
    {
        return (bool) $ingredient->delete();
    }

    public function updateStock(Ingredient $ingredient, float $newStock): bool
    {
        return $ingredient->update(['stock' => $newStock]);
    }
}
