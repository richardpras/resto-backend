<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItemRecipeSnapshot;
use App\Modules\Menu\Services\PrepForecastService;
use App\Modules\Menu\Services\ProductionPlanningService;
use App\Modules\Menu\Services\ProductionShortageService;
use App\Modules\Menu\Services\RecipeVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MenuProductionController extends Controller
{
    public function __construct(
        private readonly RecipeVersionService $recipeVersionService,
        private readonly ProductionPlanningService $productionPlanningService,
        private readonly PrepForecastService $prepForecastService,
        private readonly ProductionShortageService $shortageService,
    ) {}

    public function listVersions(int $menuItem): JsonResponse
    {
        $versions = $this->recipeVersionService->listVersions($menuItem);

        return response()->json([
            'data' => $versions->map(fn ($version) => $this->recipeVersionService->formatVersion($version))->values(),
        ]);
    }

    public function showVersion(int $menuItem, int $versionId): JsonResponse
    {
        $version = $this->recipeVersionService->getVersion($menuItem, $versionId);

        return response()->json([
            'data' => $this->recipeVersionService->formatVersion($version),
        ]);
    }

    public function createVersion(Request $request, int $menuItem): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'activate' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredientId' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit' => ['nullable', 'string', 'max:32'],
        ]);

        $version = $this->recipeVersionService->createVersion(
            $menuItem,
            $validated['items'],
            $validated['name'] ?? null,
            $validated['notes'] ?? null,
            $request->user('api'),
            (bool) ($validated['activate'] ?? true),
        );

        return response()->json([
            'message' => 'Recipe version created.',
            'data' => $this->recipeVersionService->formatVersion($version),
        ], Response::HTTP_CREATED);
    }

    public function activateVersion(Request $request, int $menuItem): JsonResponse
    {
        $validated = $request->validate([
            'versionId' => ['required', 'integer', 'min:1'],
        ]);

        $version = $this->recipeVersionService->activateVersion(
            $menuItem,
            (int) $validated['versionId'],
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Recipe version activated.',
            'data' => $this->recipeVersionService->formatVersion($version),
        ]);
    }

    public function compareVersions(Request $request, int $menuItem): JsonResponse
    {
        $validated = $request->validate([
            'versionIdA' => ['required', 'integer', 'min:1'],
            'versionIdB' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->recipeVersionService->compareVersions(
                $menuItem,
                (int) $validated['versionIdA'],
                (int) $validated['versionIdB'],
            ),
        ]);
    }

    public function orderRecipeSnapshot(int $orderId): JsonResponse
    {
        $order = Order::query()->with('items')->find($orderId);
        abort_if($order === null, Response::HTTP_NOT_FOUND, 'Order not found.');

        $snapshots = OrderItemRecipeSnapshot::query()
            ->whereIn('order_item_id', $order->items->pluck('id'))
            ->get();

        return response()->json([
            'data' => [
                'orderId' => (string) $orderId,
                'snapshots' => $snapshots->map(static fn (OrderItemRecipeSnapshot $snapshot): array => [
                    'orderItemId' => (string) $snapshot->order_item_id,
                    'menuItemId' => (string) $snapshot->menu_item_id,
                    'recipeVersionId' => $snapshot->recipe_version_id !== null ? (string) $snapshot->recipe_version_id : null,
                    'versionNumber' => (int) $snapshot->version_number,
                    'recipeSnapshot' => $snapshot->recipe_snapshot_json,
                    'snapshotAt' => $snapshot->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function productionPlan(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $menuDemands = $this->resolveMenuDemands($request, $outletId);

        return response()->json([
            'data' => $this->productionPlanningService->generateProductionPlan(
                $outletId,
                $menuDemands,
                $request->user('api'),
            ),
        ]);
    }

    public function prepForecast(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $period = (string) $request->query('period', 'range');
        $fromDate = (string) $request->query('fromDate', now()->toDateString());
        $toDate = (string) $request->query('toDate', $fromDate);

        $data = match ($period) {
            'daily' => $this->prepForecastService->forecastDaily($outletId, $fromDate, $request->user('api')),
            'weekly' => $this->prepForecastService->forecastWeekly($outletId, $fromDate, $request->user('api')),
            default => $this->prepForecastService->forecastForRange($outletId, $fromDate, $toDate, 'range', $request->user('api')),
        };

        return response()->json(['data' => $data]);
    }

    public function ingredientDemand(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $menuDemands = $this->resolveMenuDemands($request, $outletId);

        return response()->json([
            'data' => $this->productionPlanningService->generateIngredientDemand($outletId, $menuDemands),
        ]);
    }

    public function shortages(Request $request): JsonResponse
    {
        $outletId = $this->requireOutletId($request);
        $menuDemands = $this->resolveMenuDemands($request, $outletId);

        return response()->json([
            'data' => $this->shortageService->detectShortages($outletId, $menuDemands, $request->user('api')),
        ]);
    }

    private function requireOutletId(Request $request): int
    {
        $raw = $request->query('outletId');
        abort_unless(is_numeric($raw) && (int) $raw >= 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return (int) $raw;
    }

    /** @return array<int,array{menuItemId:int,quantity:float}> */
    private function resolveMenuDemands(Request $request, int $outletId): array
    {
        $menuItemId = $request->query('menuItemId');
        $quantity = $request->query('quantity');

        if (is_numeric($menuItemId) && is_numeric($quantity)) {
            return [[
                'menuItemId' => (int) $menuItemId,
                'quantity' => (float) $quantity,
            ]];
        }

        $demands = $request->input('menuDemands');
        if (is_array($demands) && $demands !== []) {
            return collect($demands)->map(static fn (array $row): array => [
                'menuItemId' => (int) $row['menuItemId'],
                'quantity' => (float) $row['quantity'],
            ])->values()->all();
        }

        return $this->productionPlanningService->deriveMenuDemandFromOrders(
            $outletId,
            $request->query('fromDate'),
            $request->query('toDate'),
        );
    }
}
