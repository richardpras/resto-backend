<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\StoreShiftRequest;
use App\Modules\HR\Http\Requests\UpdateShiftRequest;
use App\Modules\HR\Http\Resources\ShiftResource;
use App\Modules\HR\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $service,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);

        return response()->json([
            'data' => ShiftResource::collection($this->service->listByTenant($tenantId)),
        ]);
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $shift = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Shift created successfully.',
            'data' => new ShiftResource($shift),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateShiftRequest $request, int $shift): JsonResponse
    {
        $updatedShift = $this->service->update($shift, $request->validated());

        return response()->json([
            'message' => 'Shift updated successfully.',
            'data' => new ShiftResource($updatedShift),
        ]);
    }

    public function destroy(int $shift): JsonResponse
    {
        $this->service->delete($shift);

        return response()->json([
            'message' => 'Shift deleted successfully.',
        ]);
    }
}
