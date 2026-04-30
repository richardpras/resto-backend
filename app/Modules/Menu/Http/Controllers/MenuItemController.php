<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\DTOs\CreateMenuItemData;
use App\Modules\Menu\DTOs\UpdateMenuItemData;
use App\Modules\Menu\Http\Requests\StoreMenuItemRequest;
use App\Modules\Menu\Http\Requests\UpdateMenuItemRequest;
use App\Modules\Menu\Http\Resources\MenuItemResource;
use App\Modules\Menu\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MenuItemController extends Controller
{
    public function __construct(
        private readonly MenuService $menuService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);

        $menuItems = $this->menuService->listByTenant($tenantId, (int) request()->query('perPage', 20));

        return response()->json([
            'data' => MenuItemResource::collection($menuItems->getCollection()),
            'meta' => [
                'current_page' => $menuItems->currentPage(),
                'perPage' => $menuItems->perPage(),
                'total' => $menuItems->total(),
                'lastPage' => $menuItems->lastPage(),
            ],
        ]);
    }

    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $menuItem = $this->menuService->create(CreateMenuItemData::fromArray($request->validated()));

        return response()->json([
            'message' => 'Menu item created successfully.',
            'data' => new MenuItemResource($menuItem),
        ], Response::HTTP_CREATED);
    }

    public function show(int $menuItem): JsonResponse
    {
        $menuItemData = $this->menuService->find($menuItem);
        abort_if($menuItemData === null, Response::HTTP_NOT_FOUND, 'Menu item not found');

        return response()->json([
            'data' => new MenuItemResource($menuItemData),
        ]);
    }

    public function update(UpdateMenuItemRequest $request, int $menuItem): JsonResponse
    {
        $updated = $this->menuService->update($menuItem, UpdateMenuItemData::fromArray($request->validated()));
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Menu item not found');

        return response()->json([
            'message' => 'Menu item updated successfully.',
            'data' => new MenuItemResource($updated),
        ]);
    }
}
