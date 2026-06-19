<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Purchase\Http\Requests\StoreWarehouseRequest;
use App\Modules\Purchase\Http\Requests\UpdateWarehouseRequest;
use App\Modules\Purchase\Http\Resources\WarehouseResource;
use App\Modules\Purchase\Services\PurchaseScopeService;
use App\Modules\Purchase\Services\WarehouseService;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly WarehouseService $warehouseService,
    ) {}

    public function index(): JsonResponse
    {
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();
        $query = Warehouse::query()->where('is_active', true)->orderBy('name');

        if (is_numeric($outletId) && (int) $outletId >= 1) {
            $query->where(function ($builder) use ($outletId): void {
                $builder->whereNull('outlet_id')->orWhere('outlet_id', (int) $outletId);
            });
        }

        return response()->json([
            'data' => WarehouseResource::collection($query->get()),
        ]);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->warehouseService->create($request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Warehouse created successfully.',
            'data' => new WarehouseResource($warehouse),
        ], 201);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $updated = $this->warehouseService->update($warehouse, $request->user('api'), $request->validated());

        return response()->json([
            'message' => 'Warehouse updated successfully.',
            'data' => new WarehouseResource($updated),
        ]);
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $deactivated = $this->warehouseService->deactivate($warehouse, request()->user('api'));

        return response()->json([
            'message' => 'Warehouse deactivated successfully.',
            'data' => new WarehouseResource($deactivated),
        ]);
    }
}
