<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Modules\Menu\DTOs\CreateMenuItemData;
use App\Modules\Menu\DTOs\UpdateMenuItemData;
use App\Modules\Menu\Repositories\MenuRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            abort_if(
                $data->menuCategoryId === null,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Menu category is required. Use menuCategoryId instead of legacy category text.'
            );
            $category = $this->assertMenuCategoryForTenant($data->menuCategoryId, $data->tenantId);
            $productionStationId = $this->resolveProductionStationForCategory(
                $category,
                $data->outletId !== null ? (int) $data->outletId : 0
            );

            $menuItem = $this->menuRepository->create([
                'tenant_id' => $data->tenantId,
                'outlet_id' => $data->outletId,
                'name' => $data->name,
                'category' => (string) $category->name,
                'menu_category_id' => (int) $category->id,
                'production_station_id' => $productionStationId,
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

            if ($data->updateMenuCategoryId) {
                abort_if(
                    $data->menuCategoryId === null,
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'Menu category is required. Use menuCategoryId instead of legacy category text.'
                );
                $this->assertMenuCategoryForTenant(
                    $data->menuCategoryId,
                    $menuItem->tenant_id !== null ? (int) $menuItem->tenant_id : null
                );
            }

            $attributes = array_filter([
                'name' => $data->name,
                'emoji' => $data->emoji,
                'price' => $data->price,
                'available' => $data->available,
            ], static fn ($value) => $value !== null);

            if ($data->updateMenuCategoryId && $data->menuCategoryId !== null) {
                $category = $this->assertMenuCategoryForTenant(
                    $data->menuCategoryId,
                    $menuItem->tenant_id !== null ? (int) $menuItem->tenant_id : null
                );
                $attributes['menu_category_id'] = (int) $category->id;
                $attributes['category'] = (string) $category->name;
                $attributes['production_station_id'] = $this->resolveProductionStationForCategory(
                    $category,
                    $menuItem->outlet_id !== null ? (int) $menuItem->outlet_id : 0
                );
            }

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

    private function assertMenuCategoryForTenant(?int $menuCategoryId, ?int $tenantId): MenuCategory
    {
        abort_if($menuCategoryId === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Menu category is required.');
        $category = MenuCategory::query()->find($menuCategoryId);
        abort_if($category === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Menu category not found.');

        if ($tenantId !== null && $tenantId > 0 && (int) ($category->tenant_id ?? 0) !== $tenantId) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Menu category must belong to the same tenant.');
        }

        return $category;
    }

    private function resolveProductionStationForCategory(MenuCategory $category, int $outletId): ?int
    {
        if ($outletId <= 0) {
            return null;
        }

        $stationCode = $this->inferStationCodeForCategoryName((string) $category->name);
        $station = ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->where('code', $stationCode)
            ->where('is_active', true)
            ->first();

        if ($station === null && $stationCode === 'dessert') {
            $station = ProductionStation::query()
                ->where('outlet_id', $outletId)
                ->where('code', 'kitchen')
                ->where('is_active', true)
                ->first();
        }

        return $station !== null ? (int) $station->id : null;
    }

    private function inferStationCodeForCategoryName(string $categoryName): string
    {
        $category = strtolower(trim($categoryName));

        return match (true) {
            in_array($category, ['retail'], true) => 'cashier',
            in_array($category, ['beverage', 'bar'], true) => 'bar',
            in_array($category, ['dessert'], true) => 'dessert',
            default => 'kitchen',
        };
    }

    private function resolveCreateMenuCategory(?int $menuCategoryId, ?int $tenantId, ?string $categoryName = null): MenuCategory
    {
        if ($menuCategoryId !== null) {
            return $this->assertMenuCategoryForTenant($menuCategoryId, $tenantId);
        }

        $normalizedCategoryName = is_string($categoryName) ? trim($categoryName) : '';
        if ($normalizedCategoryName !== '') {
            $existingByName = MenuCategory::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw('LOWER(name) = ?', [strtolower($normalizedCategoryName)])
                ->first();
            if ($existingByName instanceof MenuCategory) {
                return $existingByName;
            }

            $baseCode = Str::slug(Str::lower($normalizedCategoryName), '_');
            if ($baseCode === '') {
                $baseCode = 'category';
            }
            $code = $baseCode;
            $suffix = 1;
            while (MenuCategory::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->exists()) {
                $suffix++;
                $code = substr($baseCode, 0, max(1, 75)).'_'.$suffix;
            }

            return MenuCategory::query()->create([
                'tenant_id' => $tenantId,
                'code' => Str::lower($code),
                'name' => $normalizedCategoryName,
                'name_en' => $normalizedCategoryName,
                'name_id' => $normalizedCategoryName,
                'description' => null,
                'description_en' => null,
                'description_id' => null,
                'sort_order' => 100,
                'is_active' => true,
            ]);
        }

        $query = MenuCategory::query()
            ->when($tenantId !== null && $tenantId > 0, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($q): void {
                $q->whereRaw('LOWER(code) = ?', ['uncategorized'])
                    ->orWhereRaw('LOWER(name) = ?', ['uncategorized']);
            })
            ->orderBy('id');
        $existing = $query->first();
        if ($existing instanceof MenuCategory) {
            return $existing;
        }

        $code = 'uncategorized';
        $suffix = 1;
        while (MenuCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->exists()) {
            $suffix++;
            $code = 'uncategorized_'.$suffix;
        }

        return MenuCategory::query()->create([
            'tenant_id' => $tenantId,
            'code' => Str::lower($code),
            'name' => 'Uncategorized',
            'name_en' => 'Uncategorized',
            'name_id' => 'Tidak Berkategori',
            'description' => 'Auto-generated fallback category',
            'description_en' => 'Auto-generated fallback category',
            'description_id' => 'Kategori default yang dibuat otomatis',
            'sort_order' => 9999,
            'is_active' => true,
        ]);
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
