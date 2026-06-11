<?php

namespace App\Modules\Print\Services;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PrinterRoutingService
{
    public function __construct(
        private readonly PrintQueueStateService $stateService,
        private readonly PrinterStationResolver $stationResolver,
    ) {}

    public function queueKitchenTicketsForOrder(Order $order): void
    {
        $order->loadMissing('items');
        $items = $this->withRoutingContextForOrderItems($order);
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $routes = PrinterRoute::query()
            ->where('outlet_id', $outletId)
            ->where('print_type', 'kitchen')
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        $groups = [];
        foreach ($items as $item) {
            $stationResolution = $this->stationResolver->resolveForOrderItemRow($item, $outletId);
            if ($stationResolution['skip']) {
                continue;
            }

            $resolved = $this->resolveKitchenRouteForItem($routes, $item, $stationResolution);
            $route = $resolved['route'];
            $station = $stationResolution['station'];
            $stationCode = $resolved['stationCode'] ?? $stationResolution['resolvedStationCode'];
            $stationId = $station?->id;

            $groupKey = implode('|', [
                (string) ($route?->id ?? 'fallback'),
                (string) ($route?->printer_profile_id ?? 'none'),
                (string) ($stationId ?? 'none'),
                (string) ($stationCode ?? 'none'),
                (string) $resolved['resolutionLayer'],
            ]);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'route' => $route,
                    'items' => [],
                    'meta' => [
                        'resolution_layer' => $resolved['resolutionLayer'],
                        'resolved_station' => $stationCode,
                        'source_category' => $stationResolution['category'],
                        'resolved_printer_profile_id' => $route?->printer_profile_id,
                        'matched_route_id' => $route?->id,
                        'production_station_id' => $stationId,
                        'productionStationId' => $stationId,
                        'station_code' => $stationCode,
                        'stationCode' => $stationCode,
                        'station_name' => $station?->name,
                        'stationName' => $station?->name,
                    ],
                ];
            }
            $groups[$groupKey]['items'][] = $item;
        }

        foreach ($groups as $group) {
            /** @var ?PrinterRoute $route */
            $route = $group['route'];
            /** @var array<string,mixed> $routeResolutionMeta */
            $routeResolutionMeta = $group['meta'];
            /** @var array<int,array<string,mixed>> $groupItems */
            $groupItems = $group['items'];
            $stationCode = is_string($routeResolutionMeta['stationCode'] ?? null)
                ? (string) $routeResolutionMeta['stationCode']
                : 'legacy';

            $this->enqueuePrintJob(
                outletId: $outletId,
                sourceType: 'order',
                sourceId: (int) $order->id,
                type: 'kitchen',
                route: $route,
                printableSnapshot: [
                    'order_id' => (int) $order->id,
                    'station' => $routeResolutionMeta['stationCode'] ?? $routeResolutionMeta['resolved_station'] ?? null,
                    'category' => $routeResolutionMeta['source_category'],
                    'resolved_printer_profile_id' => $routeResolutionMeta['resolved_printer_profile_id'],
                    'route_resolution' => $routeResolutionMeta,
                    'items' => array_values($groupItems),
                ],
                idempotencyKey: 'order-confirmed-'.(int) $order->id.'-'.$stationCode,
                routeResolutionMeta: $routeResolutionMeta,
            );
        }
    }

    public function queueReceiptForOrder(Order $order, string $reason = 'order-paid'): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $route = PrinterRoute::query()
            ->where('outlet_id', $outletId)
            ->where('print_type', 'receipt')
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        $this->enqueuePrintJob(
            outletId: $outletId,
            sourceType: 'order',
            sourceId: (int) $order->id,
            type: 'receipt',
            route: $route,
            printableSnapshot: [
                'order_id' => (int) $order->id,
                'table_name' => $order->table_name,
                'amount' => (float) $order->paid_total,
                'reason' => $reason,
            ],
            idempotencyKey: $reason.'-'.(int) $order->id
        );
    }

    /**
     * @param  array<string,mixed>  $printableSnapshot
     */
    public function enqueuePrintJob(
        int $outletId,
        string $sourceType,
        int $sourceId,
        string $type,
        ?PrinterRoute $route,
        array $printableSnapshot,
        string $idempotencyKey,
        ?int $receiptRenderHistoryId = null,
        ?array $routeResolutionMeta = null,
    ): PrintJob {
        $profileId = $route?->printer_profile_id;
        $routeId = $route?->id;
        $dedupeKey = sha1($outletId.'|'.$sourceType.'|'.$sourceId.'|'.$type.'|'.$idempotencyKey.'|'.$routeId.'|'.json_encode($printableSnapshot));

        $job = DB::transaction(function () use ($outletId, $sourceType, $sourceId, $type, $route, $profileId, $routeId, $idempotencyKey, $dedupeKey, $printableSnapshot, $receiptRenderHistoryId, $routeResolutionMeta): PrintJob {
            $existing = PrintJob::query()
                ->where('outlet_id', $outletId)
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                $created = PrintJob::query()->create([
                    'tenant_id' => 1,
                    'outlet_id' => $outletId,
                    'type' => $type,
                    'printer_id' => $profileId !== null ? (string) $profileId : null,
                    'printer_profile_id' => $profileId,
                    'printer_route_id' => $routeId,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'receipt_render_history_id' => $receiptRenderHistoryId,
                    'idempotency_key' => $idempotencyKey,
                    'dedupe_key' => $dedupeKey,
                    'content' => [
                        'sourceType' => $sourceType,
                        'sourceId' => $sourceId,
                        'type' => $type,
                    ],
                    'printable_snapshot' => $printableSnapshot,
                    'route_snapshot' => $this->buildRouteSnapshot($route, $routeResolutionMeta),
                    'status' => 'pending',
                    'attempts' => 0,
                    'queued_at' => now(),
                    'next_retry_at' => now(),
                    'max_attempts' => 5,
                    'retryable' => true,
                    'recovery_state' => 'none',
                ]);
            } catch (QueryException) {
                /** @var PrintJob $created */
                $created = PrintJob::query()->where('outlet_id', $outletId)->where('dedupe_key', $dedupeKey)->firstOrFail();
            }

            $this->stateService->appendEvent($created, 'queued', 'pending', [
                'idempotency_key' => $idempotencyKey,
                'route_id' => $routeId,
            ]);
            $this->stateService->emitLifecycle($created->fresh(), 'queued');

            return $created;
        });

        ProcessPrintJob::dispatch((int) $job->id, $outletId);

        return $job;
    }

    private function withRoutingContextForOrderItems(Order $order): Collection
    {
        $itemIds = $order->items->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $menuItems = MenuItem::query()
            ->whereIn('id', $itemIds)
            ->get(['id', 'category', 'production_station_id'])
            ->keyBy('id');

        return $order->items->map(function ($item) use ($menuItems): array {
            $menuItem = $menuItems->get((int) $item->item_id);

            return [
                'order_item_id' => (int) $item->id,
                'item_id' => (int) $item->item_id,
                'name' => (string) $item->name,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
                'category' => (string) ($menuItem?->category ?? 'uncategorized'),
                'production_station_id' => $menuItem?->production_station_id !== null
                    ? (int) $menuItem->production_station_id
                    : null,
            ];
        });
    }

    /**
     * @param  Collection<int,PrinterRoute>  $routes
     * @param  array<string,mixed>  $item
     * @param  array{skip:bool,station:?ProductionStation,category:string,resolvedStationCode:?string}  $stationResolution
     * @return array{route:?PrinterRoute,resolutionLayer:string,stationCode:?string}
     */
    private function resolveKitchenRouteForItem(Collection $routes, array $item, array $stationResolution): array
    {
        $itemCategory = (string) $stationResolution['category'];
        $itemId = (int) ($item['item_id'] ?? 0);
        $productionStationId = $item['production_station_id'] ?? $stationResolution['station']?->id;
        $resolvedStationCode = $stationResolution['resolvedStationCode'];

        $matchers = [
            'item_override' => function (PrinterRoute $route) use ($itemId): bool {
                $scope = (string) ($route->route_scope ?? data_get($route->meta, 'routeScope', ''));
                $routeItemId = (int) ($route->item_id ?? data_get($route->meta, 'itemId', 0));

                return ($scope === 'item' || $routeItemId > 0) && $routeItemId === $itemId;
            },
            'production_station' => function (PrinterRoute $route) use ($productionStationId): bool {
                if ($productionStationId === null) {
                    return false;
                }

                return $route->production_station_id !== null
                    && (int) $route->production_station_id === (int) $productionStationId;
            },
            'station_mapping' => function (PrinterRoute $route) use ($resolvedStationCode): bool {
                if ($resolvedStationCode === null || $resolvedStationCode === '') {
                    return false;
                }
                $routeCode = $route->station_code ?? $route->station;
                if ($routeCode === null || $routeCode === '') {
                    return false;
                }

                return strcasecmp((string) $routeCode, (string) $resolvedStationCode) === 0;
            },
            'category_mapping' => fn (PrinterRoute $route): bool => $route->category !== null && $route->category !== '' && strcasecmp((string) $route->category, $itemCategory) === 0,
            'outlet_default' => fn (PrinterRoute $route): bool => ($route->category === null || $route->category === '')
                && ($route->station === null || $route->station === '')
                && $route->production_station_id === null
                && ($route->station_code === null || $route->station_code === ''),
            'outlet_fallback' => fn (PrinterRoute $route): bool => false,
        ];

        foreach ($matchers as $layer => $matcher) {
            if ($layer === 'outlet_fallback') {
                break;
            }
            $matched = $routes->filter($matcher)->sortBy('priority')->first();
            if ($matched instanceof PrinterRoute) {
                return [
                    'route' => $matched,
                    'resolutionLayer' => $layer,
                    'stationCode' => $resolvedStationCode,
                ];
            }
        }

        return [
            'route' => null,
            'resolutionLayer' => 'outlet_fallback',
            'stationCode' => $resolvedStationCode,
        ];
    }

    /**
     * @param  ?array<string,mixed>  $routeResolutionMeta
     * @return ?array<string,mixed>
     */
    private function buildRouteSnapshot(?PrinterRoute $route, ?array $routeResolutionMeta): ?array
    {
        $base = $route !== null ? [
            'route_id' => (int) $route->id,
            'route_scope' => (string) ($route->route_scope ?? 'default'),
            'item_id' => $route->item_id !== null ? (int) $route->item_id : null,
            'station' => $route->station,
            'category' => $route->category,
            'priority' => (int) $route->priority,
            'production_station_id' => $route->production_station_id,
            'station_code' => $route->station_code,
        ] : [];

        if ($routeResolutionMeta === null) {
            return $route !== null ? $base : null;
        }

        return array_merge($base, $routeResolutionMeta);
    }
}
