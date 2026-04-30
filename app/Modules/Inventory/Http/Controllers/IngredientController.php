<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\DTOs\CreateIngredientData;
use App\Modules\Inventory\Http\Requests\StoreIngredientRequest;
use App\Modules\Inventory\Http\Requests\UpdateIngredientRequest;
use App\Modules\Inventory\Http\Resources\IngredientResource;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class IngredientController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);
        $ingredients = $this->inventoryService->listIngredients($tenantId, (int) request()->query('perPage', 20));

        return response()->json([
            'data' => IngredientResource::collection($ingredients->getCollection()),
            'meta' => [
                'current_page' => $ingredients->currentPage(),
                'perPage' => $ingredients->perPage(),
                'total' => $ingredients->total(),
                'lastPage' => $ingredients->lastPage(),
            ],
        ]);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $ingredient = $this->inventoryService->createIngredient(
            CreateIngredientData::fromArray($request->validated())
        );

        return response()->json([
            'message' => 'Ingredient created successfully.',
            'data' => new IngredientResource($ingredient),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateIngredientRequest $request, int $ingredient): JsonResponse
    {
        $updated = $this->inventoryService->updateIngredient($ingredient, $request->validated());
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Ingredient not found');

        return response()->json([
            'message' => 'Ingredient updated successfully.',
            'data' => new IngredientResource($updated),
        ]);
    }

    public function destroy(int $ingredient): JsonResponse
    {
        $deleted = $this->inventoryService->deleteIngredient($ingredient);
        abort_if(! $deleted, Response::HTTP_NOT_FOUND, 'Ingredient not found');

        return response()->json([
            'message' => 'Ingredient deleted successfully.',
        ]);
    }
}
