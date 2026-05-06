<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Modules\Orders\Http\Requests\ListTablesRequest;
use App\Modules\Orders\Http\Requests\StoreRestaurantTableRequest;
use App\Modules\Orders\Http\Requests\UpdateRestaurantTableRequest;
use App\Modules\Orders\Http\Resources\RestaurantTableResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TableMasterController extends Controller
{
    public function index(ListTablesRequest $request): JsonResponse
    {
        $outletId = (int) $request->validated('outletId');
        $rows = RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => RestaurantTableResource::collection($rows),
        ]);
    }

    public function store(StoreRestaurantTableRequest $request): JsonResponse
    {
        $data = $request->validated();

        $row = RestaurantTable::query()->create([
            'outlet_id' => (int) $data['outletId'],
            'name' => (string) $data['name'],
            'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
            'status' => (string) $data['status'],
        ]);

        return response()->json([
            'message' => 'Table created successfully.',
            'data' => new RestaurantTableResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateRestaurantTableRequest $request, RestaurantTable $table): JsonResponse
    {
        $validated = $request->validated();
        if (isset($validated['name'])) {
            $table->name = (string) $validated['name'];
        }
        if (array_key_exists('capacity', $validated)) {
            $table->capacity = $validated['capacity'] !== null ? (int) $validated['capacity'] : null;
        }
        if (isset($validated['status'])) {
            $table->status = (string) $validated['status'];
        }
        $table->save();

        return response()->json([
            'message' => 'Table updated successfully.',
            'data' => new RestaurantTableResource($table->fresh()),
        ]);
    }

    public function destroy(RestaurantTable $table): JsonResponse
    {
        $table->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }
}
