<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\DTOs\CreateStockMovementData;
use App\Modules\Inventory\Http\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StockMovementController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenant_id', 0);
        abort_if($tenantId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'tenant_id is required');

        $outletFilter = (int) request()->query('outlet_id', 0);

        $movements = $this->inventoryService->listStockMovements(
            $tenantId,
            (int) request()->query('per_page', 20),
            $outletFilter > 0 ? $outletFilter : null,
        );

        return response()->json([
            'data' => StockMovementResource::collection($movements->getCollection()),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'last_page' => $movements->lastPage(),
            ],
        ]);
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $movement = $this->inventoryService->addStockMovement(
            CreateStockMovementData::fromArray($request->validated())
        );

        return response()->json([
            'message' => 'Stock movement created successfully.',
            'data' => new StockMovementResource($movement->load('ingredient')),
        ], Response::HTTP_CREATED);
    }
}
