<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\ApplyOrderVoucherRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Services\OrderVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderVoucherController extends Controller
{
    public function __construct(
        private readonly OrderVoucherService $orderVoucherService,
    ) {}

    public function apply(ApplyOrderVoucherRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->orderVoucherService->apply(
            $user,
            $order,
            (int) $request->validated('memberVoucherId'),
        );

        return response()->json([
            'message' => 'Voucher applied successfully.',
            'data' => new OrderResource($result['order']),
            'preview' => $result['preview'],
        ]);
    }

    public function remove(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->orderVoucherService->remove($user, $order);

        return response()->json([
            'message' => 'Voucher removed successfully.',
            'data' => new OrderResource($result['order']),
            'preview' => $result['preview'],
        ]);
    }

    public function preview(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $preview = $this->orderVoucherService->preview($user, $order);

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
