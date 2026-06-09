<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItemOutlet;
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
        private readonly RecipeVersionService $recipeVersionService,
    ) {}

    public function listByTenant(int $tenantId, int $perPage = 20, ?int $outletId = null)
    {
        return $this->menuRepository->paginateByTenant($tenantId, $perPage, $outletId);
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

            $this->syncRecipes($menuItem->id, (int) $menuItem->tenant_id, $data->recipes);
            $this->syncOutletMappings($menuItem->id, $data->menuItemOutlets);

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
                $this->syncRecipes($menuItem->id, (int) $menuItem->tenant_id, $data->recipes);
            }

            if ($data->menuItemOutlets !== null) {
                $this->syncOutletMappings($menuItem->id, $data->menuItemOutlets);
            }

            return $this->menuRepository->findWithRecipes($menuItem->id);
        });
    }

    private function syncRecipes(int $menuItemId, int $tenantId, array $recipes): void
    {
        $ingredientIds = collect($recipes)
            ->map(static fn (array $recipe): int => (int) ($recipe['inventoryItemId'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $validIngredientCount = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->where('type', 'ingredient')
            ->where('tenant_id', $tenantId)
            ->count();
        abort_if(
            $ingredientIds->count() !== $validIngredientCount,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Recipes can only use inventory items with type ingredient.'
        );

        $this->recipeVersionService->createVersionFromRecipes($menuItemId, $recipes);
    }

    private function syncOutletMappings(int $menuItemId, array $menuItemOutlets): void
    {
        MenuItemOutlet::query()->where('menu_item_id', $menuItemId)->delete();

        foreach ($menuItemOutlets as $row) {
            $nameOverride = isset($row['nameOverride']) ? trim((string) $row['nameOverride']) : null;
            $receiptName = isset($row['receiptName']) ? trim((string) $row['receiptName']) : null;
            MenuItemOutlet::query()->create([
                'menu_item_id' => $menuItemId,
                'outlet_id' => (int) $row['outletId'],
                'is_active' => (bool) ($row['isActive'] ?? true),
                'price_override' => isset($row['priceOverride']) ? (float) $row['priceOverride'] : null,
                'name_override' => $nameOverride !== '' ? $nameOverride : null,
                'receipt_name' => $receiptName !== '' ? $receiptName : null,
            ]);
        }
    }
}
