<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\AdjustQrOrderRequest;
use App\Modules\Orders\Http\Requests\CallQrOrderCashierRequest;
use App\Modules\Orders\Http\Requests\ConfirmQrOrderRequest;
use App\Modules\Orders\Http\Requests\ListQrOrderPendingSummaryRequest;
use App\Modules\Orders\Http\Requests\ListQrOrderRequestsRequest;
use App\Modules\Orders\Http\Requests\RejectQrOrderRequest;
use App\Modules\Orders\Http\Requests\ScanQrOrderRequest;
use App\Modules\Orders\Http\Requests\SearchQrOrderRequest;
use App\Modules\Orders\Http\Requests\StoreQrOrderRequest;
use App\Modules\Orders\Http\Resources\QrOrderPendingSummaryEntryResource;
use App\Modules\Orders\Http\Resources\QrOrderPosOpenResource;
use App\Modules\Orders\Http\Resources\QrOrderPreviewResource;
use App\Modules\Orders\Http\Resources\QrOrderRequestResource;
use App\Modules\Orders\Http\Resources\QrOrderReviewResource;
use App\Modules\Orders\Services\QrOrderApprovalService;
use App\Modules\Orders\Services\QrOrderCustomerHealthService;
use App\Modules\Orders\Services\QrOrderLifecycleService;
use App\Modules\Orders\Services\QrOrderPosIntegrationService;
use App\Modules\Orders\Services\QrOrderRequestService;
use App\Modules\Orders\Services\QrOrderReviewService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class QrOrderController extends Controller
{
    public function __construct(
        private readonly QrOrderRequestService $qrOrderRequestService,
        private readonly QrOrderApprovalService $qrOrderApprovalService,
        private readonly QrOrderReviewService $qrOrderReviewService,
        private readonly QrOrderPosIntegrationService $qrOrderPosIntegrationService,
        private readonly QrOrderLifecycleService $qrOrderLifecycleService,
        private readonly QrOrderCustomerHealthService $qrOrderCustomerHealthService,
    ) {}

    public function store(StoreQrOrderRequest $request): JsonResponse
    {
        $qrOrderRequest = $this->qrOrderRequestService->create($request->validated());

        return response()->json([
            'message' => 'QR order request submitted successfully.',
            'data' => new QrOrderRequestResource($qrOrderRequest),
        ], Response::HTTP_CREATED);
    }

    public function callCashier(CallQrOrderCashierRequest $request, int $qrOrderRequest): JsonResponse
    {
        $called = $this->qrOrderRequestService->callCashier(
            $qrOrderRequest,
            (int) $request->validated('outletId'),
            (int) $request->validated('tableId'),
            $request->validated('reason'),
            $request->validated('guestSessionToken'),
        );

        return response()->json([
            'message' => 'Cashier has been notified.',
            'data' => new QrOrderRequestResource($called),
        ]);
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
                'search' => $request->validated('search'),
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

    public function pendingSummary(ListQrOrderPendingSummaryRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $summary = $this->qrOrderRequestService->pendingSummary(
            $user,
            (int) $request->validated('outletId'),
        );

        return response()->json([
            'data' => [
                'count' => $summary['count'],
                'ids' => $summary['ids'],
                'entries' => QrOrderPendingSummaryEntryResource::collection(collect($summary['entries'])),
            ],
        ]);
    }

    public function search(SearchQrOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        try {
            $found = $this->qrOrderReviewService->search($user, (string) $request->validated('code'));
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'QR order not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => new QrOrderReviewResource($found),
        ]);
    }

    public function scan(ScanQrOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        try {
            $found = $this->qrOrderReviewService->search($user, (string) $request->validated('code'));
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'QR order not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => new QrOrderReviewResource($found),
        ]);
    }

    public function review(int $qrOrderRequest): JsonResponse
    {
        $user = request()->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $preview = $this->qrOrderPosIntegrationService->preview($user, $qrOrderRequest);

        return response()->json([
            'data' => new QrOrderPreviewResource($preview),
        ]);
    }

    public function openInPos(int $qrOrderRequest): JsonResponse
    {
        $user = request()->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->qrOrderPosIntegrationService->openInPos($user, $qrOrderRequest);

        return response()->json([
            'message' => 'QR order loaded for POS.',
            'data' => new QrOrderPosOpenResource($result),
        ]);
    }

    public function adjust(AdjustQrOrderRequest $request, int $qrOrderRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $adjusted = $this->qrOrderReviewService->adjust($user, $qrOrderRequest, $request->validated());

        return response()->json([
            'message' => 'QR order adjustments saved.',
            'data' => new QrOrderReviewResource($adjusted),
        ]);
    }

    public function history(int $qrOrderRequest): JsonResponse
    {
        $user = request()->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return response()->json([
            'data' => $this->qrOrderReviewService->history($user, $qrOrderRequest),
        ]);
    }

    public function confirm(ConfirmQrOrderRequest $request, int $qrOrderRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $mode = (string) ($request->validated('mode') ?? 'confirm_only');
        $confirmed = $this->qrOrderApprovalService->confirm(
            $user,
            $qrOrderRequest,
            $mode,
            $request->validated('payments', []),
            $request->validated('idempotencyKey') ?? $request->header('Idempotency-Key'),
        );

        $message = $mode === 'pay_and_confirm'
            ? 'QR order paid and sent to kitchen.'
            : 'QR order confirmed as open bill and sent to kitchen.';

        return response()->json([
            'message' => $message,
            'data' => new QrOrderRequestResource($confirmed),
        ]);
    }

    public function confirmAndPay(ConfirmQrOrderRequest $request, int $qrOrderRequest): JsonResponse
    {
        $request->merge(['mode' => 'pay_and_confirm']);

        return $this->confirm($request, $qrOrderRequest);
    }

    public function customerHealth(): JsonResponse
    {
        $user = request()->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $outletId = request()->query('outletId');
        $parsedOutletId = is_numeric($outletId) ? (int) $outletId : null;

        return response()->json([
            'data' => $this->qrOrderCustomerHealthService->snapshot($user, $parsedOutletId),
        ]);
    }

    public function markServed(int $qrOrderRequest): JsonResponse
    {
        $user = request()->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $served = $this->qrOrderLifecycleService->markServed($user, $qrOrderRequest);

        return response()->json([
            'message' => 'QR order marked as served.',
            'data' => new QrOrderRequestResource($served),
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
