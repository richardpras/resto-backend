<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Orders\Http\Requests\PreviewRecoverySettlementRequest;
use App\Modules\Orders\Http\Requests\RecordRecoverySettlementRequest;
use App\Modules\Orders\Services\RecoverySettlement\RecoverySettlementApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderItemRecoverySettlementController extends Controller
{
    public function __construct(
        private readonly RecoverySettlementApplicationService $settlementApplicationService,
    ) {}

    public function preview(PreviewRecoverySettlementRequest $request, int $order, int $orderItem): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $data = $this->settlementApplicationService->preview($user, $order, $orderItem, $request->validated());

        return response()->json([
            'message' => 'Recovery settlement preview (informational; no payments mutated).',
            'data' => $data,
        ]);
    }

    public function record(RecordRecoverySettlementRequest $request, int $order, int $orderItem): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->settlementApplicationService->recordAudit($user, $order, $orderItem, $request->validated());

        return response()->json([
            'message' => $result['idempotent'] ? 'Recovery settlement audit already recorded (idempotent).' : 'Recovery settlement audit recorded.',
            'data' => $result,
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof User ? $user : null;
    }
}
