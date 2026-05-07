<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Modules\Orders\Http\Requests\ListTablesRequest;
use App\Modules\Orders\Http\Requests\StoreRestaurantTableRequest;
use App\Modules\Orders\Http\Requests\UpdateRestaurantTableRequest;
use App\Modules\Orders\Http\Resources\RestaurantTableResource;
use App\Modules\Orders\Services\TableMasterService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TableMasterController extends Controller
{
    public function __construct(
        private readonly TableMasterService $service,
    ) {}

    public function index(ListTablesRequest $request): JsonResponse
    {
        $outletId = (int) $request->validated('outletId');
        $rows = $this->service->listForOutlet($request->user(), $outletId);

        return response()->json([
            'data' => RestaurantTableResource::collection($rows),
        ]);
    }

    public function store(StoreRestaurantTableRequest $request): JsonResponse
    {
        $row = $this->service->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'Table created successfully.',
            'data' => new RestaurantTableResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateRestaurantTableRequest $request, RestaurantTable $table): JsonResponse
    {
        $table = $this->service->update($request->user(), (int) $table->id, $request->validated());

        return response()->json([
            'message' => 'Table updated successfully.',
            'data' => new RestaurantTableResource($table),
        ]);
    }

    public function destroy(RestaurantTable $table): JsonResponse
    {
        $this->service->delete(request()->user(), (int) $table->id);

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }
}
