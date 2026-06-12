<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\InventoryConsumptionQueueService;
use App\Modules\Inventory\Services\InventoryPostingHealthService;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryConsumptionController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly InventoryPostingHealthService $postingHealthService,
        private readonly InventoryConsumptionQueueService $queueService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'data' => $this->queueService->listForOutlet(
                (int) $validated['outletId'],
                $validated['status'] ?? null,
                (int) ($validated['limit'] ?? 50),
            ),
        ]);
    }

    public function post(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->orderService->postDeferredInventoryConsumption(
            $request->user(),
            (int) $validated['outletId'],
        );

        return response()->json([
            'message' => 'Inventory consumption posting completed.',
            'data' => $result,
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
        ]);

        $outletId = isset($validated['outletId']) ? (int) $validated['outletId'] : null;

        return response()->json([
            'data' => $this->postingHealthService->summarize($outletId),
        ]);
    }
}
