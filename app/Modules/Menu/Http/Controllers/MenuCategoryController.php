<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\ListMenuCategoriesRequest;
use App\Modules\Menu\Http\Requests\StoreMenuCategoryRequest;
use App\Modules\Menu\Http\Requests\UpdateMenuCategoryRequest;
use App\Modules\Menu\Http\Resources\MenuCategoryResource;
use App\Modules\Menu\Services\MenuCategoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MenuCategoryController extends Controller
{
    public function __construct(
        private readonly MenuCategoryService $service,
    ) {}

    public function index(ListMenuCategoriesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = isset($validated['tenantId']) ? (int) $validated['tenantId'] : null;
        $activeOnly = (bool) ($validated['activeOnly'] ?? false);

        return response()->json([
            'data' => MenuCategoryResource::collection($this->service->list($tenantId, $activeOnly)),
        ]);
    }

    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Menu category created successfully.',
            'data' => new MenuCategoryResource($category),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateMenuCategoryRequest $request, int $menuCategory): JsonResponse
    {
        $category = $this->service->update($menuCategory, $request->validated());

        return response()->json([
            'message' => 'Menu category updated successfully.',
            'data' => new MenuCategoryResource($category),
        ]);
    }
}
