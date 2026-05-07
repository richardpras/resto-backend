<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\ListQrOrderRequestsRequest;
use App\Modules\Orders\Http\Requests\RejectQrOrderRequest;
use App\Modules\Orders\Http\Requests\StoreQrOrderRequest;
use App\Modules\Orders\Http\Resources\QrOrderRequestResource;
use App\Modules\Orders\Services\QrOrderApprovalService;
use App\Modules\Orders\Services\QrOrderRequestService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class QrOrderController extends Controller
{
    public function __construct(
        private readonly QrOrderRequestService $qrOrderRequestService,
        private readonly QrOrderApprovalService $qrOrderApprovalService,
    ) {}

    public function store(StoreQrOrderRequest $request): JsonResponse
    {
        $qrOrderRequest = $this->qrOrderRequestService->create($request->validated());

        return response()->json([
            'message' => 'QR order request submitted successfully.',
            'data' => new QrOrderRequestResource($qrOrderRequest),
        ], Response::HTTP_CREATED);
    }

    public function index(ListQrOrderRequestsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $requests = $this->qrOrderRequestService->list(
            $user,
            (int) $request->validated('perPage', 20),
            [
                'outlet_id' => $request->validated('outletId'),
                'status' => $request->validated('status'),
            ],
        );

        return response()->json([
            'data' => QrOrderRequestResource::collection(collect($requests->items())),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'currentPage' => $requests->currentPage(),
                'perPage' => $requests->perPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'lastPage' => $requests->lastPage(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    public function confirm(\Illuminate\Http\Request $request, int $qrOrderRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $confirmed = $this->qrOrderApprovalService->confirm(
            $user,
            $qrOrderRequest,
            $request->header('Idempotency-Key')
        );

        return response()->json([
            'message' => 'QR order request confirmed successfully.',
            'data' => new QrOrderRequestResource($confirmed),
        ]);
    }

    public function reject(RejectQrOrderRequest $request, int $qrOrderRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $rejected = $this->qrOrderApprovalService->reject(
            $user,
            $qrOrderRequest,
            $request->validated('reason'),
            $request->validated('idempotencyKey') ?? $request->header('Idempotency-Key'),
        );

        return response()->json([
            'message' => 'QR order request rejected successfully.',
            'data' => new QrOrderRequestResource($rejected),
        ]);
    }
}
