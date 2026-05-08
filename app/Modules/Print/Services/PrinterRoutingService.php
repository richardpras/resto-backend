<?php

namespace App\Modules\Print\Services;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PrinterRoutingService
{
    public function __construct(
        private readonly PrintQueueStateService $stateService,
    ) {}

    public function queueKitchenTicketsForOrder(Order $order): void
    {
        $order->loadMissing('items');
        $items = $this->withCategoryForOrderItems($order);
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
            $resolved = $this->resolveKitchenRouteForItem($routes, $item);
            $route = $resolved['route'];
            $groupKey = implode('|', [
                (string) ($route?->id ?? 'fallback'),
                (string) $resolved['resolvedStation'],
                (string) $resolved['sourceCategory'],
                (string) $resolved['resolutionLayer'],
            ]);
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'route' => $route,
                    'items' => [],
                    'meta' => [
                        'resolution_layer' => $resolved['resolutionLayer'],
                        'resolved_station' => $resolved['resolvedStation'],
                        'source_category' => $resolved['sourceCategory'],
                        'resolved_printer_profile_id' => $route?->printer_profile_id,
                        'matched_route_id' => $route?->id,
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

            $this->enqueuePrintJob(
                outletId: $outletId,
                sourceType: 'order',
                sourceId: (int) $order->id,
                type: 'kitchen',
                route: $route,
                printableSnapshot: [
                    'order_id' => (int) $order->id,
                    'station' => $routeResolutionMeta['resolved_station'],
                    'category' => $routeResolutionMeta['source_category'],
                    'resolved_printer_profile_id' => $routeResolutionMeta['resolved_printer_profile_id'],
                    'route_resolution' => $routeResolutionMeta,
                    'items' => array_values($groupItems),
                ],
                idempotencyKey: 'order-confirmed-'.(int) $order->id,
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
                    'route_snapshot' => $route !== null ? array_merge([
                        'route_id' => (int) $route->id,
                        'route_scope' => (string) ($route->route_scope ?? 'default'),
                        'item_id' => $route->item_id !== null ? (int) $route->item_id : null,
                        'station' => $route->station,
                        'category' => $route->category,
                        'priority' => (int) $route->priority,
                    ], $routeResolutionMeta ?? []) : $routeResolutionMeta,
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

    private function withCategoryForOrderItems(Order $order): Collection
    {
        $itemIds = $order->items->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $categoryById = MenuItem::query()
            ->whereIn('id', $itemIds)
            ->pluck('category', 'id');

        return $order->items->map(function ($item) use ($categoryById): array {
            return [
                'order_item_id' => (int) $item->id,
                'item_id' => (int) $item->item_id,
                'name' => (string) $item->name,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
                'category' => (string) ($categoryById[(int) $item->item_id] ?? 'uncategorized'),
            ];
        });
    }

    private function applyRouteFilter(Collection $items, PrinterRoute $route): Collection
    {
        return $items->filter(function (array $item) use ($route): bool {
            if ($route->category !== null && $route->category !== '' && $item['category'] !== $route->category) {
                return false;
            }
            if ($route->station !== null && $route->station !== '') {
                $station = $item['category'] === 'uncategorized' ? 'default' : strtolower((string) $item['category']);
                if ($station !== strtolower((string) $route->station)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int,PrinterRoute>  $routes
     * @param  array<string,mixed>  $item
     * @return array{route:?PrinterRoute,resolutionLayer:string,resolvedStation:?string,sourceCategory:string}
     */
    private function resolveKitchenRouteForItem(Collection $routes, array $item): array
    {
        $itemCategory = (string) ($item['category'] ?? 'uncategorized');
        $itemId = (int) ($item['item_id'] ?? 0);
        $resolvedStation = $this->resolveStationForCategory($itemCategory);

        $matchers = [
            'item_override' => function (PrinterRoute $route) use ($itemId): bool {
                $scope = (string) ($route->route_scope ?? data_get($route->meta, 'routeScope', ''));
                $routeItemId = (int) ($route->item_id ?? data_get($route->meta, 'itemId', 0));

                return ($scope === 'item' || $routeItemId > 0) && $routeItemId === $itemId;
            },
            'category_mapping' => fn (PrinterRoute $route): bool => $route->category !== null && $route->category !== '' && strcasecmp((string) $route->category, $itemCategory) === 0,
            'station_mapping' => fn (PrinterRoute $route): bool => $route->station !== null && $route->station !== '' && $resolvedStation !== null && strcasecmp((string) $route->station, $resolvedStation) === 0,
            'outlet_default' => fn (PrinterRoute $route): bool => ($route->category === null || $route->category === '') && ($route->station === null || $route->station === ''),
        ];

        foreach ($matchers as $layer => $matcher) {
            $matched = $routes->filter($matcher)->sortBy('priority')->first();
            if ($matched instanceof PrinterRoute) {
                return [
                    'route' => $matched,
                    'resolutionLayer' => $layer,
                    'resolvedStation' => $resolvedStation,
                    'sourceCategory' => $itemCategory,
                ];
            }
        }

        return [
            'route' => null,
            'resolutionLayer' => 'outlet_fallback',
            'resolvedStation' => $resolvedStation,
            'sourceCategory' => $itemCategory,
        ];
    }

    private function resolveStationForCategory(string $category): ?string
    {
        $map = config('print.category_station_map', []);
        if (is_array($map) && isset($map[$category]) && is_string($map[$category])) {
            return strtolower((string) $map[$category]);
        }

        return $category === 'uncategorized' ? 'default' : strtolower($category);
    }
}
