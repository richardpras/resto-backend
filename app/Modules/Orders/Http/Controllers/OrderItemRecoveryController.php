<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\ApproveOrderItemRecoveryRequest;
use App\Modules\Orders\Http\Requests\ReportOrderItemRecoveryRequest;
use App\Modules\Orders\Http\Resources\OrderItemRecoveryEventResource;
use App\Modules\Orders\Services\OrderItemRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderItemRecoveryController extends Controller
{
    public function __construct(
        private readonly OrderItemRecoveryService $recoveryService,
    ) {}

    public function index(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $rows = $this->recoveryService->listEventsForOrder($user, $order);

        return response()->json([
            'data' => OrderItemRecoveryEventResource::collection($rows),
        ]);
    }

    public function report(ReportOrderItemRecoveryRequest $request, int $order, int $orderItem): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $item = $this->recoveryService->reportIssue(
            $user,
            $order,
            $orderItem,
            (string) $request->validated('targetStatus'),
            $request->validated('reason'),
        );

        return response()->json([
            'message' => 'Recovery report recorded.',
            'data' => [
                'orderItemId' => (int) $item->id,
                'recoveryStatus' => $item->recovery_status,
                'recoveryReason' => $item->recovery_reason,
            ],
        ]);
    }

    public function approve(ApproveOrderItemRecoveryRequest $request, int $order, int $orderItem): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $item = $this->recoveryService->approveResolution(
            $user,
            $order,
            $orderItem,
            (string) $request->validated('resolution'),
            $request->validated('notes'),
            $request->validated('payload'),
        );

        return response()->json([
            'message' => 'Recovery resolution recorded.',
            'data' => [
                'orderItemId' => (int) $item->id,
                'recoveryStatus' => $item->recovery_status,
                'recoveryReason' => $item->recovery_reason,
                'recoveryApprovedAt' => $item->recovery_approved_at?->toISOString(),
                'recoveryApprovedByUserId' => $item->recovery_approved_by_user_id,
                'replacedByOrderItemId' => $item->replaced_by_order_item_id,
            ],
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
