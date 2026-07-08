<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\SaveDailyStocktakeCountsRequest;
use App\Modules\Inventory\Http\Requests\StoreDailyStocktakeSessionRequest;
use App\Modules\Inventory\Http\Resources\DailyStocktakeSessionResource;
use App\Modules\Inventory\Services\DailyStocktakePostingService;
use App\Modules\Inventory\Services\DailyStocktakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyStocktakeController extends Controller
{
    public function __construct(
        private readonly DailyStocktakeService $stocktakeService,
        private readonly DailyStocktakePostingService $postingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = (int) $request->query('outletId', 0);
        abort_if($outletId < 1, 422, 'outletId is required.');

        $sessions = $this->stocktakeService->listSessions(
            $outletId,
            $request->query('from'),
            $request->query('to'),
        );

        return response()->json([
            'data' => DailyStocktakeSessionResource::collection($sessions->load('lines.ingredient:id,name,unit')),
        ]);
    }

    public function store(StoreDailyStocktakeSessionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $session = $this->stocktakeService->getOrCreateSession(
            (int) $validated['outletId'],
            (string) $validated['businessDate'],
            $request->user('api'),
        );

        return response()->json([
            'data' => new DailyStocktakeSessionResource($session),
        ], 201);
    }

    public function show(int $sessionId): JsonResponse
    {
        $session = $this->stocktakeService->findSession($sessionId);

        return response()->json([
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }

    public function saveOpening(int $sessionId, SaveDailyStocktakeCountsRequest $request): JsonResponse
    {
        $session = $this->stocktakeService->saveOpening(
            $sessionId,
            $request->validated('lines'),
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Opening counts saved.',
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }

    public function saveClosing(int $sessionId, SaveDailyStocktakeCountsRequest $request): JsonResponse
    {
        $session = $this->stocktakeService->saveClosing(
            $sessionId,
            $request->validated('lines'),
            $request->user('api'),
        );

        return response()->json([
            'message' => 'Closing counts saved.',
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }

    public function submit(int $sessionId, Request $request): JsonResponse
    {
        $session = $this->stocktakeService->submitForApproval($sessionId, $request->user('api'));

        return response()->json([
            'message' => 'Stocktake submitted for approval.',
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }

    public function approve(int $sessionId, Request $request): JsonResponse
    {
        $session = $this->postingService->approveAndPost($sessionId, $request->user('api'));

        return response()->json([
            'message' => 'Stocktake approved and posted.',
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }

    public function cancel(int $sessionId, Request $request): JsonResponse
    {
        $session = $this->stocktakeService->cancel($sessionId, $request->user('api'));

        return response()->json([
            'message' => 'Stocktake cancelled.',
            'data' => new DailyStocktakeSessionResource($session),
        ]);
    }
}
