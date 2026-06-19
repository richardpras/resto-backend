<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\ApplyOrderPromotionByCodeRequest;
use App\Modules\Orders\Http\Requests\ApplyOrderPromotionRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Services\OrderPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderPromotionController extends Controller
{
    public function __construct(
        private readonly OrderPromotionService $orderPromotionService,
    ) {}

    public function apply(ApplyOrderPromotionRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->orderPromotionService->apply(
            $user,
            $order,
            (int) $request->validated('promotionId'),
        );

        return response()->json([
            'message' => 'Promotion applied successfully.',
            'data' => new OrderResource($result['order']),
            'preview' => $result['preview'],
        ]);
    }

    public function applyByCode(ApplyOrderPromotionByCodeRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->orderPromotionService->applyByCode(
            $user,
            $order,
            (string) $request->validated('code'),
        );

        return response()->json([
            'message' => 'Promotion applied successfully.',
            'data' => new OrderResource($result['order']),
            'preview' => $result['preview'],
        ]);
    }

    public function remove(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->orderPromotionService->remove($user, $order);

        return response()->json([
            'message' => 'Promotion removed successfully.',
            'data' => new OrderResource($result['order']),
            'preview' => $result['preview'],
        ]);
    }

    public function preview(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $preview = $this->orderPromotionService->preview($user, $order);

        return response()->json([
            'preview' => $preview,
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
