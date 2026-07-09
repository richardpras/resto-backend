<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Resources\DailyStocktakeSessionResource;
use App\Modules\Inventory\Services\DailyStocktakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DailyStocktakeController extends Controller
{
    public function __construct(
        private readonly DailyStocktakeService $stocktakeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $sessions = $this->stocktakeService->listSessions(
            (int) $validated['outletId'],
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return response()->json([
            'data' => DailyStocktakeSessionResource::collection($sessions),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'businessDate' => ['required', 'date'],
        ]);

        $session = $this->stocktakeService->createSession(
            (int) $validated['outletId'],
            $validated['businessDate'],
            $request->user('api'),
        );

        return response()->json([
            'data' => new DailyStocktakeSessionResource($session),
        ], Response::HTTP_CREATED);
    }

    public function show(int $sessionId): JsonResponse
    {
        $session = $this->stocktakeService->loadSession($sessionId);

        return response()->json([
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }

    public function saveOpening(Request $request, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.ingredientId' => ['required', 'integer', 'min:1'],
            'lines.*.openingQty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $session = $this->stocktakeService->loadSession($sessionId);
        $updated = $this->stocktakeService->saveOpening($session, $validated['lines']);

        return response()->json([
            'data' => new DailyStocktakeSessionResource($updated),
        ]);
    }

    public function saveClosing(Request $request, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.ingredientId' => ['required', 'integer', 'min:1'],
            'lines.*.closingQty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $session = $this->stocktakeService->loadSession($sessionId);
        $updated = $this->stocktakeService->saveClosing($session, $validated['lines']);

        return response()->json([
            'data' => new DailyStocktakeSessionResource($updated),
        ]);
    }

    public function submit(int $sessionId): JsonResponse
    {
        $session = $this->stocktakeService->loadSession($sessionId);
        $updated = $this->stocktakeService->submit($session);

        return response()->json([
            'message' => 'Daily stocktake submitted for approval.',
            'data' => new DailyStocktakeSessionResource($updated),
        ]);
    }

    public function approve(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->stocktakeService->loadSession($sessionId);
        $updated = $this->stocktakeService->approve($session, $request->user('api'));

        return response()->json([
            'message' => 'Daily stocktake approved and posted.',
            'data' => new DailyStocktakeSessionResource($updated),
        ]);
    }

    public function cancel(int $sessionId): JsonResponse
    {
        $session = $this->stocktakeService->loadSession($sessionId);
        $updated = $this->stocktakeService->cancel($session);

        return response()->json([
            'message' => 'Daily stocktake session cancelled.',
            'data' => new DailyStocktakeSessionResource($updated),
        ]);
    }
}
