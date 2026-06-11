<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Requests\ListProductionStationsRequest;
use App\Modules\Production\Http\Requests\StoreProductionStationRequest;
use App\Modules\Production\Http\Requests\UpdateProductionStationRequest;
use App\Modules\Production\Http\Requests\UpdateProductionStationStatusRequest;
use App\Modules\Production\Http\Resources\ProductionStationResource;
use App\Modules\Production\Services\ProductionStationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProductionStationController extends Controller
{
    public function __construct(
        private readonly ProductionStationService $service,
    ) {}

    public function index(ListProductionStationsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $stations = $this->service->listForOutlet(
            (int) $validated['outletId'],
            (bool) ($validated['activeOnly'] ?? false),
        );

        return response()->json([
            'data' => ProductionStationResource::collection($stations),
        ]);
    }

    public function store(StoreProductionStationRequest $request): JsonResponse
    {
        $station = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Production station created successfully.',
            'data' => new ProductionStationResource($station),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateProductionStationRequest $request, int $productionStation): JsonResponse
    {
        $station = $this->service->update($productionStation, $request->validated());

        return response()->json([
            'message' => 'Production station updated successfully.',
            'data' => new ProductionStationResource($station),
        ]);
    }

    public function updateStatus(UpdateProductionStationStatusRequest $request, int $productionStation): JsonResponse
    {
        $station = $this->service->updateStatus($productionStation, (bool) $request->validated()['isActive']);

        return response()->json([
            'message' => 'Production station status updated successfully.',
            'data' => new ProductionStationResource($station),
        ]);
    }
}
