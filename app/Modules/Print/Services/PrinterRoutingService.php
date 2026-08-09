<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Modules\Settings\Services\OutletLogoService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrinterRoutingService
{
    public function __construct(
        private readonly PrintQueueStateService $stateService,
        private readonly PrinterStationResolver $stationResolver,
        private readonly PrintDispatchService $dispatchService,
        private readonly CashierPrinterResolver $cashierPrinterResolver,
        private readonly ThermalPaperWidthResolver $thermalPaperWidthResolver,
        private readonly ThermalReceiptLayoutBuilder $thermalReceiptLayout,
        private readonly OutletLogoService $outletLogoService,
        private readonly ReceiptOrderSnapshotBuilder $orderSnapshotBuilder,
    ) {}

    public function queueKitchenTicketsForOrder(Order $order): void
    {
        if (! (bool) config('print.category_mapping.enabled', true)) {
            Log::warning('print.routing.disabled', [
                'reason' => 'print.category_mapping.enabled is false',
                'order_id' => (int) $order->id,
            ]);

            return;
        }

        $order->loadMissing('items');
        $items = $this->withRoutingContextForOrderItems($order);
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $categoryMappings = MenuCategoryPrinterMapping::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->groupBy('menu_category_id')
            ->map(fn (Collection $rows) => $rows->first());

        $groups = [];
        foreach ($items as $item) {
            $stationResolution = $this->stationResolver->resolveForOrderItemRow($item, $outletId);
            if ($stationResolution['skip']) {
                continue;
            }

            $resolved = $this->resolveKitchenRouteForItem($categoryMappings, $item, $outletId);
            if ($resolved === null) {
                continue;
            }

            $menuCategoryId = (int) $resolved['menuCategoryId'];
            $resolvedProfileId = (int) $resolved['printerProfileId'];
            $groupKey = $menuCategoryId.'|'.$resolvedProfileId;

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'route' => null,
                    'items' => [],
                    'meta' => [
                        'resolution_layer' => $resolved['resolutionLayer'],
                        'menu_category_id' => $menuCategoryId,
                        'menu_category_name' => $resolved['menuCategoryName'],
                        'source_category' => $resolved['menuCategoryName'],
                        'resolved_printer_profile_id' => $resolvedProfileId,
                        'matched_category_mapping_id' => $resolved['categoryMappingId'],
                    ],
                ];
            }
            $groups[$groupKey]['items'][] = $item;
        }

        foreach ($groups as $group) {
            /** @var array<string,mixed> $routeResolutionMeta */
            $routeResolutionMeta = $group['meta'];
            /** @var array<int,array<string,mixed>> $groupItems */
            $groupItems = $group['items'];
            $menuCategoryId = (int) ($routeResolutionMeta['menu_category_id'] ?? 0);
            $groupProfileId = (int) ($routeResolutionMeta['resolved_printer_profile_id'] ?? 0);

            Log::info('print.routing.resolved', [
                'outlet_id' => $outletId,
                'order_id' => (int) $order->id,
                'menu_category_id' => $menuCategoryId,
                'resolution_layer' => (string) ($routeResolutionMeta['resolution_layer'] ?? 'category_master_mapping'),
                'printer_profile_id' => $groupProfileId,
            ]);

            $width = $this->thermalPaperWidthResolver->resolveWidthCharsForProfileId($groupProfileId);
            $printableSnapshot = [
                'order_id' => (int) $order->id,
                'order_code' => (string) ($order->code ?? ''),
                'table_name' => (string) ($order->table_name ?? ''),
                'order_type' => (string) ($order->order_type ?? ''),
                'category' => $routeResolutionMeta['menu_category_name'] ?? null,
                'station' => $routeResolutionMeta['menu_category_name'] ?? 'kitchen',
                'menu_category_id' => $menuCategoryId,
                'resolved_printer_profile_id' => $groupProfileId,
                'thermal_width_chars' => $width,
                'route_resolution' => $routeResolutionMeta,
                'items' => array_values($groupItems),
            ];
            // Bake lines (incl. item notes) at enqueue so bridge + reprint share one layout.
            $printableSnapshot['thermalDocument'] = $this->thermalReceiptLayout->buildKitchenTicketDocument(
                $printableSnapshot,
                $width,
                (string) ($routeResolutionMeta['menu_category_name'] ?? 'kitchen'),
            );

            $this->enqueuePrintJob(
                outletId: $outletId,
                sourceType: 'order',
                sourceId: (int) $order->id,
                type: 'kitchen',
                route: null,
                printableSnapshot: $printableSnapshot,
                idempotencyKey: 'order-confirmed-'.(int) $order->id.'-cat-'.$menuCategoryId,
                routeResolutionMeta: $routeResolutionMeta,
                resolvedProfileId: $groupProfileId,
            );
        }
    }

    public function queueReceiptForOrder(Order $order, string $reason = 'order-paid'): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $order->loadMissing(['items', 'payments', 'orderPromotion', 'orderVoucher', 'splits.items']);

        ['route' => $route, 'resolvedProfileId' => $resolvedProfileId] = $this->resolveReceiptRouting($outletId);
        $profile = $this->cashierPrinterResolver->resolveForOutlet($outletId);
        $width = $profile !== null
            ? $this->thermalPaperWidthResolver->resolveWidthChars($profile)
            : 32;

        $branding = $this->resolveReceiptBranding($outletId);
        $printableSnapshot = $this->orderSnapshotBuilder->buildFromOrder(
            $order,
            $outletId,
            null,
            null,
            $branding,
        );
        $printableSnapshot['order_id'] = (int) $order->id;
        $printableSnapshot['amount'] = (float) $order->paid_total;
        $printableSnapshot['reason'] = $reason;

        $thermalRaster = null;
        if ($branding['showLogo'] ?? false) {
            $outlet = Outlet::query()->find($outletId);
            if ($outlet !== null) {
                $paperKey = OutletLogoService::paperKeyForWidthChars($width);
                $thermalRaster = $this->outletLogoService->loadThermalRaster($outlet, $paperKey);
            }
        }

        $printableSnapshot['thermalDocument'] = $this->thermalReceiptLayout->buildCustomerReceiptDocument(
            $printableSnapshot,
            $width,
            $thermalRaster,
        );

        $this->enqueuePrintJob(
            outletId: $outletId,
            sourceType: 'order',
            sourceId: (int) $order->id,
            type: 'receipt',
            route: $route,
            printableSnapshot: $printableSnapshot,
            idempotencyKey: $reason.'-'.(int) $order->id,
            resolvedProfileId: $resolvedProfileId,
        );
    }

    public function ensureKitchenPrintJobsForOrder(Order $order): void
    {
        $this->syncKitchenPrintJobsForOrder($order);
    }

    public function syncKitchenPrintJobsForOrder(Order $order): void
    {
        $status = (string) $order->status;
        if (! in_array($status, ['confirmed', 'completed'], true)) {
            return;
        }

        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $order->loadMissing('items');
        $this->queueKitchenTicketsForOrder($order);
    }

    /**
     * Reprint kitchen tickets for a subset of order items (new idempotency key per request).
     *
     * @param  list<int>  $orderItemIds
     * @return array{printJobIds:list<int>,groupedByStation:list<array{station:string,menuCategoryName:string,itemCount:int}>}
     */
    public function queueKitchenReprintForOrderItems(Order $order, array $orderItemIds): array
    {
        if (! (bool) config('print.category_mapping.enabled', true)) {
            return ['printJobIds' => [], 'groupedByStation' => []];
        }

        $order->loadMissing('items');
        $idSet = array_flip($orderItemIds);
        $items = $this->withRoutingContextForOrderItems($order)
            ->filter(fn (array $item): bool => isset($idSet[(int) ($item['order_item_id'] ?? 0)]))
            ->values();

        $outletId = (int) ($order->outlet_id ?? 0);
        if ($outletId < 1 || $items->isEmpty()) {
            return ['printJobIds' => [], 'groupedByStation' => []];
        }

        $categoryMappings = MenuCategoryPrinterMapping::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->groupBy('menu_category_id')
            ->map(fn (Collection $rows) => $rows->first());

        $groups = [];
        foreach ($items as $item) {
            $stationResolution = $this->stationResolver->resolveForOrderItemRow($item, $outletId);
            if ($stationResolution['skip']) {
                continue;
            }

            $resolved = $this->resolveKitchenRouteForItem($categoryMappings, $item, $outletId);
            if ($resolved === null) {
                continue;
            }

            $menuCategoryId = (int) $resolved['menuCategoryId'];
            $resolvedProfileId = (int) $resolved['printerProfileId'];
            $groupKey = $menuCategoryId.'|'.$resolvedProfileId;

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'items' => [],
                    'meta' => [
                        'resolution_layer' => $resolved['resolutionLayer'],
                        'menu_category_id' => $menuCategoryId,
                        'menu_category_name' => $resolved['menuCategoryName'],
                        'source_category' => $resolved['menuCategoryName'],
                        'resolved_printer_profile_id' => $resolvedProfileId,
                        'matched_category_mapping_id' => $resolved['categoryMappingId'],
                    ],
                ];
            }
            $groups[$groupKey]['items'][] = $item;
        }

        $printJobIds = [];
        $groupedByStation = [];
        $sortedItemKey = sha1(implode(',', collect($orderItemIds)->sort()->values()->all()));

        foreach ($groups as $group) {
            /** @var array<string,mixed> $routeResolutionMeta */
            $routeResolutionMeta = $group['meta'];
            /** @var array<int,array<string,mixed>> $groupItems */
            $groupItems = $group['items'];
            $menuCategoryId = (int) ($routeResolutionMeta['menu_category_id'] ?? 0);
            $groupProfileId = (int) ($routeResolutionMeta['resolved_printer_profile_id'] ?? 0);

            $width = $this->thermalPaperWidthResolver->resolveWidthCharsForProfileId($groupProfileId);
            $printableSnapshot = [
                'order_id' => (int) $order->id,
                'order_code' => (string) ($order->code ?? ''),
                'table_name' => (string) ($order->table_name ?? ''),
                'order_type' => (string) ($order->order_type ?? ''),
                'category' => $routeResolutionMeta['menu_category_name'] ?? null,
                'station' => $routeResolutionMeta['menu_category_name'] ?? 'kitchen',
                'menu_category_id' => $menuCategoryId,
                'resolved_printer_profile_id' => $groupProfileId,
                'thermal_width_chars' => $width,
                'route_resolution' => $routeResolutionMeta,
                'items' => array_values($groupItems),
                'reprint' => true,
            ];
            $printableSnapshot['thermalDocument'] = $this->thermalReceiptLayout->buildKitchenTicketDocument(
                $printableSnapshot,
                $width,
                (string) ($routeResolutionMeta['menu_category_name'] ?? 'kitchen'),
            );

            $job = $this->enqueuePrintJob(
                outletId: $outletId,
                sourceType: 'order',
                sourceId: (int) $order->id,
                type: 'kitchen',
                route: null,
                printableSnapshot: $printableSnapshot,
                idempotencyKey: 'kitchen-reprint-'.(int) $order->id.'-'.$sortedItemKey.'-cat-'.$menuCategoryId.'-'.uniqid('', true),
                routeResolutionMeta: $routeResolutionMeta,
                resolvedProfileId: $groupProfileId,
            );

            $printJobIds[] = (int) $job->id;
            $groupedByStation[] = [
                'station' => (string) ($routeResolutionMeta['menu_category_name'] ?? 'kitchen'),
                'menuCategoryName' => (string) ($routeResolutionMeta['menu_category_name'] ?? ''),
                'itemCount' => count($groupItems),
            ];
        }

        return ['printJobIds' => $printJobIds, 'groupedByStation' => $groupedByStation];
    }

    /**
     * @return array{route: ?PrinterRoute, resolvedProfileId: ?int}
     */
    private function resolveReceiptRouting(int $outletId): array
    {
        $profile = $this->cashierPrinterResolver->resolveForOutlet($outletId);
        if ($profile !== null) {
            return [
                'route' => $this->cashierPrinterResolver->resolveRouteForProfile($outletId, $profile),
                'resolvedProfileId' => (int) $profile->id,
            ];
        }

        return [
            'route' => $this->cashierPrinterResolver->resolveLegacyReceiptRoute($outletId),
            'resolvedProfileId' => null,
        ];
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
        ?int $resolvedProfileId = null,
    ): PrintJob {
        $profileId = $resolvedProfileId ?? $route?->printer_profile_id;
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

        $this->dispatchService->dispatchAfterEnqueue($job);

        return $job;
    }

    private function withRoutingContextForOrderItems(Order $order): Collection
    {
        $itemIds = $order->items->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $menuItems = MenuItem::query()
            ->whereIn('id', $itemIds)
            ->get(['id', 'category', 'menu_category_id', 'production_station_id', 'tenant_id'])
            ->keyBy('id');

        return $order->items->map(function ($item) use ($menuItems): array {
            $menuItem = $menuItems->get((int) $item->item_id);

            return [
                'order_item_id' => (int) $item->id,
                'item_id' => (int) $item->item_id,
                'name' => (string) $item->name,
                'qty' => (float) $item->qty,
                'notes' => $item->notes !== null && trim((string) $item->notes) !== ''
                    ? trim((string) $item->notes)
                    : null,
                'category' => (string) ($menuItem?->category ?? 'uncategorized'),
                'menu_category_id' => $menuItem?->menu_category_id !== null
                    ? (int) $menuItem->menu_category_id
                    : null,
                'tenant_id' => $menuItem?->tenant_id !== null ? (int) $menuItem->tenant_id : null,
                'production_station_id' => $menuItem?->production_station_id !== null
                    ? (int) $menuItem->production_station_id
                    : null,
            ];
        });
    }

    /**
     * @param  Collection<int,MenuCategoryPrinterMapping>  $categoryMappings
     * @param  array<string,mixed>  $item
     * @return ?array{resolutionLayer:string,menuCategoryId:int,menuCategoryName:string,printerProfileId:int,categoryMappingId:int}
     */
    private function resolveKitchenRouteForItem(Collection $categoryMappings, array $item, int $outletId): ?array
    {
        $menuCategory = $this->resolveMenuCategoryForItem($item);
        if ($menuCategory === null) {
            Log::warning('print.routing.unmapped_category', [
                'outlet_id' => $outletId,
                'item_id' => (int) ($item['item_id'] ?? 0),
                'reason' => 'menu_category_not_found',
            ]);

            return null;
        }

        $mapping = $categoryMappings->get((int) $menuCategory->id);
        if ($mapping instanceof MenuCategoryPrinterMapping) {
            return [
                'resolutionLayer' => 'category_master_mapping',
                'menuCategoryId' => (int) $menuCategory->id,
                'menuCategoryName' => (string) $menuCategory->name,
                'printerProfileId' => (int) $mapping->printer_profile_id,
                'categoryMappingId' => (int) $mapping->id,
            ];
        }

        // No category printer set → still split one ticket per category via outlet kitchen fallback.
        $fallbackProfileId = $this->resolveFallbackKitchenPrinterProfileId($outletId);
        if ($fallbackProfileId === null) {
            Log::warning('print.routing.unmapped_category', [
                'outlet_id' => $outletId,
                'item_id' => (int) ($item['item_id'] ?? 0),
                'menu_category_id' => (int) $menuCategory->id,
                'menu_category_name' => (string) $menuCategory->name,
                'reason' => 'no_printer_mapping_and_no_fallback',
            ]);

            return null;
        }

        Log::info('print.routing.category_split_fallback', [
            'outlet_id' => $outletId,
            'item_id' => (int) ($item['item_id'] ?? 0),
            'menu_category_id' => (int) $menuCategory->id,
            'menu_category_name' => (string) $menuCategory->name,
            'printer_profile_id' => $fallbackProfileId,
        ]);

        return [
            'resolutionLayer' => 'category_split_fallback',
            'menuCategoryId' => (int) $menuCategory->id,
            'menuCategoryName' => (string) $menuCategory->name,
            'printerProfileId' => $fallbackProfileId,
            'categoryMappingId' => 0,
        ];
    }

    /** Prefer kitchen → bar → dessert SettingPrinter / PrinterProfile for unmapped categories. */
    private function resolveFallbackKitchenPrinterProfileId(int $outletId): ?int
    {
        if ($outletId < 1) {
            return null;
        }

        $setting = SettingPrinter::query()
            ->where('outlet_id', $outletId)
            ->whereIn('printer_type', ['kitchen', 'bar', 'dessert'])
            ->orderByRaw("CASE printer_type WHEN 'kitchen' THEN 0 WHEN 'bar' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();

        if ($setting instanceof SettingPrinter && $setting->printer_profile_id !== null) {
            $fromSetting = PrinterProfile::query()->find((int) $setting->printer_profile_id);
            if ($fromSetting instanceof PrinterProfile && (bool) $fromSetting->is_active) {
                return (int) $fromSetting->id;
            }
        }

        $profile = PrinterProfile::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereRaw('LOWER(station) in (?, ?, ?)', ['kitchen', 'bar', 'dessert'])
                    ->orWhereRaw('LOWER(code) in (?, ?, ?)', ['kitchen', 'bar', 'dessert']);
            })
            ->orderByRaw("CASE LOWER(station)
                WHEN 'kitchen' THEN 0
                WHEN 'bar' THEN 1
                WHEN 'dessert' THEN 2
                ELSE 3
            END")
            ->orderBy('id')
            ->first();

        return $profile instanceof PrinterProfile ? (int) $profile->id : null;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function resolveMenuCategoryForItem(array $item): ?MenuCategory
    {
        $menuCategoryId = isset($item['menu_category_id']) ? (int) $item['menu_category_id'] : null;
        if ($menuCategoryId !== null && $menuCategoryId > 0) {
            $category = MenuCategory::query()->find($menuCategoryId);
            if ($category instanceof MenuCategory) {
                return $category;
            }
        }

        $tenantId = isset($item['tenant_id']) ? (int) $item['tenant_id'] : null;

        return MenuCategory::query()
            ->when($tenantId !== null && $tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(function ($query): void {
                $query->whereRaw('LOWER(code) = ?', ['uncategorized'])
                    ->orWhereRaw('LOWER(name) = ?', ['uncategorized']);
            })
            ->orderBy('id')
            ->first();
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

    /**
     * @return array{outletName:string,header:string,footer:string,showTaxBreakdown:bool,showLogo:bool,logoVersion:int,logoUrl:?string}
     */
    private function resolveReceiptBranding(int $outletId): array
    {
        /** @var Outlet|null $outlet */
        $outlet = Outlet::query()->with('receiptSetting')->find($outletId);
        $setting = $outlet?->receiptSetting;

        return [
            'outletName' => (string) ($outlet?->name ?? ''),
            'header' => (string) ($setting?->receipt_header ?? ''),
            'footer' => (string) ($setting?->receipt_footer ?? ''),
            'showTaxBreakdown' => (bool) ($setting?->show_tax_breakdown ?? false),
            'showLogo' => (bool) ($setting?->show_logo ?? false),
            'logoVersion' => (int) ($outlet?->logo_version ?? 0),
            'logoUrl' => $this->outletLogoService->publicUrl($outlet),
        ];
    }
}
