<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Menu\Domain\RecipeVersion;
use App\Models\Modules\Menu\Domain\RecipeVersionItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RecipeVersionService
{
    public function __construct(
        private readonly MenuProductionAuditService $auditService,
    ) {}

    public function getActiveVersion(int $menuItemId): RecipeVersion
    {
        $active = RecipeVersion::query()
            ->with('items.ingredient')
            ->where('menu_item_id', $menuItemId)
            ->where('status', 'active')
            ->first();

        if ($active !== null) {
            return $active;
        }

        return $this->bootstrapFromMenuRecipes($menuItemId);
    }

    /** @return Collection<int, RecipeVersion> */
    public function listVersions(int $menuItemId): Collection
    {
        return RecipeVersion::query()
            ->with('items.ingredient')
            ->where('menu_item_id', $menuItemId)
            ->orderByDesc('version_number')
            ->get();
    }

    public function getVersion(int $menuItemId, int $versionId): RecipeVersion
    {
        $version = RecipeVersion::query()
            ->with('items.ingredient')
            ->where('menu_item_id', $menuItemId)
            ->whereKey($versionId)
            ->first();

        abort_if($version === null, Response::HTTP_NOT_FOUND, 'Recipe version not found.');

        return $version;
    }

    /**
     * @param array<int,array{ingredientId:int,quantity:float,unit?:string|null}> $items
     */
    public function createVersion(
        int $menuItemId,
        array $items,
        ?string $name = null,
        ?string $notes = null,
        ?User $actor = null,
        bool $activate = false,
    ): RecipeVersion {
        return DB::transaction(function () use ($menuItemId, $items, $name, $notes, $actor, $activate): RecipeVersion {
            $nextNumber = (int) RecipeVersion::query()
                ->where('menu_item_id', $menuItemId)
                ->max('version_number') + 1;

            $version = RecipeVersion::query()->create([
                'menu_item_id' => $menuItemId,
                'version_number' => max(1, $nextNumber),
                'name' => $name ?? 'Version '.$nextNumber,
                'notes' => $notes,
                'status' => $activate ? 'active' : 'draft',
                'activated_at' => $activate ? now() : null,
                'created_by' => $actor?->id,
            ]);

            $this->syncVersionItems($version, $items);

            $this->auditService->log('recipe_version_created', $menuItemId, null, $actor, [
                'recipeVersionId' => (int) $version->id,
                'versionNumber' => (int) $version->version_number,
                'status' => $version->status,
            ]);

            if ($activate) {
                $this->activateVersionInternal($version, $actor);
            }

            return $version->fresh(['items.ingredient']);
        });
    }

    public function activateVersion(int $menuItemId, int $versionId, ?User $actor = null): RecipeVersion
    {
        $version = $this->getVersion($menuItemId, $versionId);

        return DB::transaction(function () use ($version, $actor): RecipeVersion {
            $this->activateVersionInternal($version, $actor);

            return $version->fresh(['items.ingredient']);
        });
    }

    public function archiveVersion(int $menuItemId, int $versionId, ?User $actor = null): RecipeVersion
    {
        $version = $this->getVersion($menuItemId, $versionId);
        abort_if(
            $version->status === 'active',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Cannot archive the active recipe version.',
        );

        $version->update(['status' => 'archived']);

        $this->auditService->log('recipe_version_archived', $menuItemId, null, $actor, [
            'recipeVersionId' => (int) $version->id,
            'versionNumber' => (int) $version->version_number,
        ]);

        return $version->fresh(['items.ingredient']);
    }

    /** @return array<string,mixed> */
    public function compareVersions(int $menuItemId, int $versionIdA, int $versionIdB): array
    {
        $versionA = $this->getVersion($menuItemId, $versionIdA);
        $versionB = $this->getVersion($menuItemId, $versionIdB);

        $itemsA = $versionA->items->keyBy('ingredient_id');
        $itemsB = $versionB->items->keyBy('ingredient_id');
        $ingredientIds = $itemsA->keys()->merge($itemsB->keys())->unique()->values();

        $changes = [];
        foreach ($ingredientIds as $ingredientId) {
            $lineA = $itemsA->get($ingredientId);
            $lineB = $itemsB->get($ingredientId);
            $qtyA = $lineA !== null ? (float) $lineA->quantity : null;
            $qtyB = $lineB !== null ? (float) $lineB->quantity : null;

            if ($qtyA === $qtyB) {
                continue;
            }

            $changes[] = [
                'ingredientId' => (string) $ingredientId,
                'ingredientName' => $lineB?->ingredient?->name ?? $lineA?->ingredient?->name,
                'quantityA' => $qtyA,
                'quantityB' => $qtyB,
                'quantityDelta' => $qtyB !== null && $qtyA !== null
                    ? round($qtyB - $qtyA, 4)
                    : null,
            ];
        }

        return [
            'menuItemId' => (string) $menuItemId,
            'versionA' => $this->formatVersion($versionA),
            'versionB' => $this->formatVersion($versionB),
            'changes' => $changes,
        ];
    }

    /**
     * @param array<int,array{inventoryItemId?:int,ingredientId?:int,quantity:float,unit?:string|null}> $recipes
     */
    public function createVersionFromRecipes(
        int $menuItemId,
        array $recipes,
        ?User $actor = null,
        ?string $notes = null,
    ): RecipeVersion {
        $items = collect($recipes)->map(static function (array $recipe): array {
            $ingredientId = (int) ($recipe['ingredientId'] ?? $recipe['inventoryItemId'] ?? 0);

            return [
                'ingredientId' => $ingredientId,
                'quantity' => (float) $recipe['quantity'],
                'unit' => $recipe['unit'] ?? null,
            ];
        })->filter(static fn (array $row): bool => $row['ingredientId'] > 0)->values()->all();

        $active = RecipeVersion::query()
            ->where('menu_item_id', $menuItemId)
            ->where('status', 'active')
            ->first();

        if ($active === null) {
            return $this->createVersion($menuItemId, $items, notes: $notes, actor: $actor, activate: true);
        }

        return $this->createVersion($menuItemId, $items, notes: $notes ?? 'Recipe updated', actor: $actor, activate: true);
    }

    /** @return array<int,array{ingredientId:int,quantity:float,unit:?string}> */
    public function getActiveRecipeLines(int $menuItemId): array
    {
        $version = $this->getActiveVersion($menuItemId);

        return $version->items->map(static fn (RecipeVersionItem $item): array => [
            'ingredientId' => (int) $item->ingredient_id,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
        ])->values()->all();
    }

    /** @return array<string,mixed> */
    public function formatVersion(RecipeVersion $version): array
    {
        return [
            'id' => (string) $version->id,
            'menuItemId' => (string) $version->menu_item_id,
            'versionNumber' => (int) $version->version_number,
            'name' => $version->name,
            'notes' => $version->notes,
            'status' => $version->status,
            'activatedAt' => $version->activated_at?->toIso8601String(),
            'items' => $version->items->map(static fn (RecipeVersionItem $item): array => [
                'ingredientId' => (string) $item->ingredient_id,
                'ingredientName' => $item->ingredient?->name,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit ?? $item->ingredient?->unit,
            ])->values()->all(),
        ];
    }

    private function activateVersionInternal(RecipeVersion $version, ?User $actor): void
    {
        RecipeVersion::query()
            ->where('menu_item_id', $version->menu_item_id)
            ->where('status', 'active')
            ->where('id', '!=', $version->id)
            ->update(['status' => 'archived']);

        $version->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $this->syncMenuRecipesFromVersion($version);

        $this->auditService->log('recipe_version_activated', (int) $version->menu_item_id, null, $actor, [
            'recipeVersionId' => (int) $version->id,
            'versionNumber' => (int) $version->version_number,
        ]);
    }

  /**
     * @param array<int,array{ingredientId:int,quantity:float,unit?:string|null}> $items
     */
    private function syncVersionItems(RecipeVersion $version, array $items): void
    {
        RecipeVersionItem::query()->where('recipe_version_id', $version->id)->delete();

        foreach ($items as $item) {
            $ingredientId = (int) $item['ingredientId'];
            $ingredient = Ingredient::query()->find($ingredientId);
            RecipeVersionItem::query()->create([
                'recipe_version_id' => $version->id,
                'ingredient_id' => $ingredientId,
                'quantity' => (float) $item['quantity'],
                'unit' => $item['unit'] ?? $ingredient?->unit,
            ]);
        }
    }

    private function syncMenuRecipesFromVersion(RecipeVersion $version): void
    {
        $version->loadMissing('items');

        MenuRecipe::query()->where('menu_item_id', $version->menu_item_id)->delete();

        foreach ($version->items as $item) {
            MenuRecipe::query()->create([
                'menu_item_id' => $version->menu_item_id,
                'inventory_item_id' => $item->ingredient_id,
                'quantity' => $item->quantity,
            ]);
        }
    }

    private function bootstrapFromMenuRecipes(int $menuItemId): RecipeVersion
    {
        return DB::transaction(function () use ($menuItemId): RecipeVersion {
            $active = RecipeVersion::query()
                ->where('menu_item_id', $menuItemId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($active !== null) {
                return $active;
            }

            $latest = RecipeVersion::query()
                ->where('menu_item_id', $menuItemId)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->first();

            if ($latest !== null) {
                if ($latest->status !== 'active') {
                    $this->activateVersionInternal($latest, null);
                }

                return $latest->fresh(['items.ingredient']);
            }

            $lines = MenuRecipe::query()->where('menu_item_id', $menuItemId)->get();
            $items = $lines->map(static fn (MenuRecipe $recipe): array => [
                'ingredientId' => (int) $recipe->inventory_item_id,
                'quantity' => (float) $recipe->quantity,
                'unit' => $recipe->ingredient?->unit,
            ])->all();

            return $this->createVersion($menuItemId, $items, name: 'Version 1', activate: true);
        });
    }
}
