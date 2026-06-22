<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\ClosePosSessionRequest;
use App\Modules\Orders\Http\Requests\CurrentPosSessionRequest;
use App\Modules\Orders\Http\Requests\OpenPosSessionRequest;
use App\Modules\Orders\Http\Resources\PosSessionResource;
use App\Modules\Orders\Services\PosSessionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PosSessionController extends Controller
{
    public function __construct(
        private readonly PosSessionService $service,
    ) {}

    public function open(OpenPosSessionRequest $request): JsonResponse
    {
        $session = $this->service->open($request->user(), $request->validated());

        return response()->json([
            'message' => 'POS session opened successfully.',
            'data' => new PosSessionResource($session),
        ], Response::HTTP_CREATED);
    }

    public function close(ClosePosSessionRequest $request, int $id): JsonResponse
    {
        $session = $this->service->close($request->user(), $id, $request->validated());

        return response()->json([
            'message' => 'POS session closed successfully.',
            'data' => new PosSessionResource($session),
        ]);
    }

    public function closePreview(int $id): JsonResponse
    {
        $user = request()->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED);

        return response()->json([
            'data' => $this->service->previewClose($user, $id),
        ]);
    }

    public function current(CurrentPosSessionRequest $request): JsonResponse
    {
        $outletId = (int) $request->validated('outletId');
        $session = $this->service->current($request->user(), $outletId);

        return response()->json([
            'data' => $session !== null ? new PosSessionResource($session) : null,
            'meta' => [
                'defaultCashFloat' => $this->service->defaultCashFloatForOutlet($outletId),
            ],
        ]);
    }
}
