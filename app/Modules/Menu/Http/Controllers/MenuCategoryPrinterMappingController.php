<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Requests\ListMenuCategoryPrinterMappingsRequest;
use App\Modules\Menu\Http\Requests\StoreMenuCategoryPrinterMappingRequest;
use App\Modules\Menu\Http\Resources\MenuCategoryPrinterMappingResource;
use App\Modules\Menu\Services\MenuCategoryPrinterMappingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MenuCategoryPrinterMappingController extends Controller
{
    public function __construct(
        private readonly MenuCategoryPrinterMappingService $service,
    ) {}

    public function index(ListMenuCategoryPrinterMappingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => MenuCategoryPrinterMappingResource::collection(
                $this->service->listForOutlet(
                    (int) $validated['outletId'],
                    (bool) ($validated['activeOnly'] ?? false)
                )
            ),
        ]);
    }

    public function store(StoreMenuCategoryPrinterMappingRequest $request): JsonResponse
    {
        $mapping = $this->service->upsert($request->validated());

        return response()->json([
            'message' => 'Category printer mapping saved successfully.',
            'data' => new MenuCategoryPrinterMappingResource($mapping),
        ], Response::HTTP_CREATED);
    }

    public function destroy(int $mapping): JsonResponse
    {
        $this->service->delete($mapping);

        return response()->json([
            'message' => 'Category printer mapping deleted successfully.',
        ]);
    }
}
