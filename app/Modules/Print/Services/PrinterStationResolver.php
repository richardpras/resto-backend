<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Production\Domain\ProductionStation;

class PrinterStationResolver
{
    /**
     * @param  array<string,mixed>  $orderItemRow  Enriched order item row (item_id, category, …)
     * @return array{
     *     skip: bool,
     *     station: ?ProductionStation,
     *     category: string,
     *     resolvedStationCode: ?string
     * }
     */
    public function resolveForOrderItemRow(array $orderItemRow, int $outletId): array
    {
        $itemId = (int) ($orderItemRow['item_id'] ?? 0);
        $category = (string) ($orderItemRow['category'] ?? 'uncategorized');
        $menuItem = $itemId > 0 ? MenuItem::query()->find($itemId) : null;

        if ($menuItem?->production_station_id !== null) {
            $station = ProductionStation::query()->find((int) $menuItem->production_station_id);
            if ($station !== null && (int) $station->outlet_id === $outletId) {
                if (! $station->is_active || ! $station->print_enabled) {
                    return [
                        'skip' => true,
                        'station' => null,
                        'category' => $category,
                        'resolvedStationCode' => null,
                    ];
                }

                return [
                    'skip' => false,
                    'station' => $station,
                    'category' => $category,
                    'resolvedStationCode' => strtolower((string) $station->code),
                ];
            }
        }

        $fromCategory = $this->resolveStationFromCategory($category, $outletId);
        if ($fromCategory !== null) {
            return [
                'skip' => false,
                'station' => $fromCategory,
                'category' => $category,
                'resolvedStationCode' => strtolower((string) $fromCategory->code),
            ];
        }

        $defaultKitchen = $this->defaultKitchenStation($outletId);
        if ($defaultKitchen !== null) {
            return [
                'skip' => false,
                'station' => $defaultKitchen,
                'category' => $category,
                'resolvedStationCode' => 'kitchen',
            ];
        }

        return [
            'skip' => false,
            'station' => null,
            'category' => $category,
            'resolvedStationCode' => $this->legacyStationCodeForCategory($category),
        ];
    }

    private function resolveStationFromCategory(string $category, int $outletId): ?ProductionStation
    {
        $code = $this->legacyStationCodeForCategory($category);
        if ($code === null) {
            return null;
        }

        $station = ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->where('is_active', true)
            ->where('print_enabled', true)
            ->first();

        return $station instanceof ProductionStation ? $station : null;
    }

    private function defaultKitchenStation(int $outletId): ?ProductionStation
    {
        $station = ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->where('code', 'kitchen')
            ->where('is_active', true)
            ->where('print_enabled', true)
            ->first();

        return $station instanceof ProductionStation ? $station : null;
    }

    private function legacyStationCodeForCategory(string $category): ?string
    {
        $map = config('print.category_station_map', []);
        if (is_array($map) && isset($map[$category]) && is_string($map[$category])) {
            return strtolower((string) $map[$category]);
        }

        if ($category === 'uncategorized') {
            return 'default';
        }

        return match (strtolower($category)) {
            'food' => 'kitchen',
            'beverage' => 'bar',
            default => strtolower($category),
        };
    }
}
