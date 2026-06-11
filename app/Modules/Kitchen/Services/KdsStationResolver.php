<?php

namespace App\Modules\Kitchen\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Support\Collection;

class KdsStationResolver
{
    /**
     * @param  Collection<int, OrderItem>  $orderItems
     * @return array{
     *     groups: array<string, array{station: ?ProductionStation, items: Collection<int, OrderItem>}>,
     *     skippedOrderItemIds: list<int>
     * }
     */
    public function groupOrderItemsByStation(Collection $orderItems, int $outletId): array
    {
        $groups = [];
        $skippedOrderItemIds = [];

        foreach ($orderItems as $orderItem) {
            $resolution = $this->resolveForOrderItem($orderItem, $outletId);
            if ($resolution['skip']) {
                $skippedOrderItemIds[] = (int) $orderItem->id;

                continue;
            }

            $key = $this->groupKeyForStation($resolution['station']);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'station' => $resolution['station'],
                    'items' => collect(),
                ];
            }
            $groups[$key]['items']->push($orderItem);
        }

        return [
            'groups' => $groups,
            'skippedOrderItemIds' => $skippedOrderItemIds,
        ];
    }

    /**
     * @return array{skip: bool, station: ?ProductionStation}
     */
    public function resolveForOrderItem(OrderItem $orderItem, int $outletId): array
    {
        $menuItem = MenuItem::query()->find((int) $orderItem->item_id);

        if ($menuItem?->production_station_id !== null) {
            $station = ProductionStation::query()->find((int) $menuItem->production_station_id);
            if ($station !== null && (int) $station->outlet_id === $outletId) {
                if (! $station->is_active || ! $station->kds_enabled) {
                    return ['skip' => true, 'station' => null];
                }

                return ['skip' => false, 'station' => $station];
            }
        }

        $category = (string) ($menuItem?->category ?? 'uncategorized');
        $fromCategory = $this->resolveStationFromCategory($category, $outletId);
        if ($fromCategory !== null) {
            return ['skip' => false, 'station' => $fromCategory];
        }

        $defaultKitchen = $this->defaultKitchenStation($outletId);
        if ($defaultKitchen !== null) {
            return ['skip' => false, 'station' => $defaultKitchen];
        }

        return ['skip' => false, 'station' => null];
    }

    private function resolveStationFromCategory(string $category, int $outletId): ?ProductionStation
    {
        $map = config('print.category_station_map', []);
        $code = is_array($map) && isset($map[$category]) && is_string($map[$category])
            ? strtolower((string) $map[$category])
            : strtolower($category);

        $code = match ($code) {
            'food' => 'kitchen',
            'beverage' => 'bar',
            default => $code,
        };

        $station = ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->where('is_active', true)
            ->where('kds_enabled', true)
            ->first();

        return $station instanceof ProductionStation ? $station : null;
    }

    private function defaultKitchenStation(int $outletId): ?ProductionStation
    {
        $station = ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->where('code', 'kitchen')
            ->where('is_active', true)
            ->where('kds_enabled', true)
            ->first();

        return $station instanceof ProductionStation ? $station : null;
    }

    private function groupKeyForStation(?ProductionStation $station): string
    {
        if ($station === null) {
            return 'legacy';
        }

        return 'station:'.(int) $station->id;
    }
}
