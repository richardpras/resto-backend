<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuRecipeCostSetting;
use App\Models\Modules\Menu\Domain\RecipeVersion;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderItemRecipeSnapshot;
use App\Models\User;

final class OrderItemRecipeSnapshotService
{
    public function __construct(
        private readonly RecipeVersionService $recipeVersionService,
        private readonly MenuProductionAuditService $auditService,
    ) {}

    public function snapshotForOrderItem(
        OrderItem $orderItem,
        int $outletId,
        ?User $actor = null,
    ): ?OrderItemRecipeSnapshot {
        if ($orderItem->item_id === null) {
            return null;
        }

        $existing = OrderItemRecipeSnapshot::query()
            ->where('order_item_id', $orderItem->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $menuItemId = (int) $orderItem->item_id;
        $version = $this->recipeVersionService->getActiveVersion($menuItemId);
        $payload = $this->buildSnapshotPayload($version, $menuItemId);

        $snapshot = OrderItemRecipeSnapshot::query()->create([
            'order_item_id' => (int) $orderItem->id,
            'recipe_version_id' => $version->id,
            'menu_item_id' => $menuItemId,
            'version_number' => (int) $version->version_number,
            'recipe_snapshot_json' => $payload,
            'created_at' => now(),
        ]);

        $this->auditService->log(
            'recipe_snapshot_created',
            $menuItemId,
            $outletId,
            $actor,
            [
                'orderItemId' => (int) $orderItem->id,
                'recipeVersionId' => (int) $version->id,
                'versionNumber' => (int) $version->version_number,
            ],
            entityType: 'order_item',
        );

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function buildSnapshotPayload(RecipeVersion $version, int $menuItemId): array
    {
        $version->loadMissing('items.ingredient');
        $costSetting = MenuRecipeCostSetting::query()->where('menu_item_id', $menuItemId)->first();

        return [
            'recipeVersionId' => (int) $version->id,
            'versionNumber' => (int) $version->version_number,
            'menuItemId' => $menuItemId,
            'yieldPercent' => (float) ($costSetting?->yield_percent ?? 100),
            'wastePercent' => (float) ($costSetting?->waste_percent ?? 0),
            'items' => $version->items->map(static fn ($item): array => [
                'ingredientId' => (int) $item->ingredient_id,
                'ingredientName' => $item->ingredient?->name,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit ?? $item->ingredient?->unit,
            ])->values()->all(),
        ];
    }
}
