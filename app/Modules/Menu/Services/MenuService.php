<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Modules\Menu\DTOs\CreateMenuItemData;
use App\Modules\Menu\DTOs\UpdateMenuItemData;
use App\Modules\Menu\Repositories\MenuRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MenuService
{
    public function __construct(
        private readonly MenuRepositoryInterface $menuRepository,
    ) {}

    public function listByTenant(int $tenantId, int $perPage = 20)
    {
        return $this->menuRepository->paginateByTenant($tenantId, $perPage);
    }

    public function find(int $id)
    {
        return $this->menuRepository->findWithRecipes($id);
    }

    public function create(CreateMenuItemData $data)
    {
        return DB::transaction(function () use ($data) {
            $menuItem = $this->menuRepository->create([
                'tenant_id' => $data->tenantId,
                'outlet_id' => $data->outletId,
                'name' => $data->name,
                'category' => $data->category,
                'emoji' => $data->emoji,
                'price' => $data->price,
                'available' => $data->available,
            ]);

            $this->syncRecipes($menuItem->id, $data->recipes);

            return $this->menuRepository->findWithRecipes($menuItem->id);
        });
    }

    public function update(int $menuItemId, UpdateMenuItemData $data)
    {
        return DB::transaction(function () use ($menuItemId, $data) {
            $menuItem = $this->menuRepository->findById($menuItemId);
            if ($menuItem === null) {
                return null;
            }

            $attributes = array_filter([
                'name' => $data->name,
                'category' => $data->category,
                'emoji' => $data->emoji,
                'price' => $data->price,
                'available' => $data->available,
            ], static fn ($value) => $value !== null);

            if ($attributes !== []) {
                $this->menuRepository->update($menuItem, $attributes);
            }

            if ($data->recipes !== null) {
                $this->syncRecipes($menuItem->id, $data->recipes);
            }

            return $this->menuRepository->findWithRecipes($menuItem->id);
        });
    }

    private function syncRecipes(int $menuItemId, array $recipes): void
    {
        $ingredientIds = collect($recipes)->pluck('ingredientId')->map(static fn ($id) => (int) $id)->unique()->values();
        $validIngredientCount = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->where('type', 'ingredient')
            ->count();
        abort_if(
            $ingredientIds->count() !== $validIngredientCount,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Recipes can only use inventory items with type ingredient.'
        );

        MenuRecipe::query()->where('menu_item_id', $menuItemId)->delete();

        foreach ($recipes as $recipe) {
            MenuRecipe::query()->create([
                'menu_item_id' => $menuItemId,
                'ingredient_id' => (int) $recipe['ingredientId'],
                'qty' => (float) $recipe['qty'],
            ]);
        }
    }
}
