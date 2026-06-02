<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Modules\Orders\Http\Resources\RestaurantTableResource;
use App\Modules\Orders\Http\Resources\TableQrResolveResource;
use App\Modules\Orders\Services\TableQrManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableQrController extends Controller
{
    public function __construct(
        private readonly TableQrManagementService $tableQrManagementService,
    ) {}

    public function generate(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->generate($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR identity generated.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function rotate(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->rotate($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR identity rotated.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function enable(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->enable($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR enabled.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function disable(Request $request, RestaurantTable $table): JsonResponse
    {
        $updated = $this->tableQrManagementService->disable($request->user(), (int) $table->id);

        return response()->json([
            'message' => 'QR disabled.',
            'data' => new RestaurantTableResource($updated),
        ], Response::HTTP_OK);
    }

    public function resolve(string $qrPublicId): JsonResponse
    {
        $table = $this->tableQrManagementService->resolveByPublicId($qrPublicId);
        if ($table === null) {
            return response()->json([
                'message' => 'QR table not found or disabled.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => new TableQrResolveResource($table, $this->tableQrManagementService),
        ]);
    }

    public function resolveLegacy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tableId' => ['required', 'integer', 'min:1'],
        ]);
        $table = $this->tableQrManagementService->resolveLegacy((int) $validated['outletId'], (int) $validated['tableId']);
        if ($table === null) {
            return response()->json([
                'message' => 'Table not found or unavailable.',
            ], Response::HTTP_NOT_FOUND);
        }
        return response()->json([
            'data' => new TableQrResolveResource($table, $this->tableQrManagementService),
            'meta' => ['compatibility' => 'legacy-query'],
        ]);
    }
}
