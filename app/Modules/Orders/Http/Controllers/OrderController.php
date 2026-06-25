<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Http\Requests\AddOrderPaymentsRequest;
use App\Modules\Orders\Http\Requests\KitchenReprintRequest;
use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Requests\NextOrderCodeRequest;
use App\Modules\Orders\Http\Requests\StoreOrderSplitRequest;
use App\Modules\Orders\Http\Requests\SyncOrderSplitsRequest;
use App\Modules\Orders\Http\Requests\ShiftClosePostingRequest;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderSplitRequest;
use App\Modules\Orders\Http\Requests\SetOrderMemberRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Http\Resources\OrderPaymentResource;
use App\Modules\Orders\Http\Resources\OrderSplitResource;
use App\Modules\Orders\Http\Resources\PosEventLogResource;
use App\Modules\Orders\Services\PaymentAllocationService;
use App\Modules\Orders\Services\OrderCodeAllocationService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\SplitBillService;
use App\Modules\Print\Services\KitchenReprintService;
use App\Models\Modules\Orders\Domain\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly SplitBillService $splitBillService,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly OrderCodeAllocationService $orderCodeAllocationService,
        private readonly KitchenReprintService $kitchenReprintService,
    ) {}

    public function nextCode(NextOrderCodeRequest $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $outletId = (int) $request->validated('outletId');
        $this->orderService->assertOutletAllowedForUser($user, $outletId);

        return response()->json([
            'data' => $this->orderCodeAllocationService->preview($outletId),
        ]);
    }

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $tenantId = (int) $request->validated('tenantId', 0);
        $orders = $this->orderService->listOrders(
            $this->resolveAuthenticatedUser($request),
            $tenantId,
            (int) $request->validated('perPage', 20),
            [
                'outlet_id' => $request->validated('outletId'),
                'payment_status' => $request->validated('paymentStatus'),
                'order_type' => $request->validated('orderType'),
                'service_mode' => $request->validated('serviceMode'),
                'kitchen_status' => $request->validated('kitchenStatus'),
                'status' => $request->validated('status'),
                'source' => $request->validated('source'),
                'search' => $request->validated('search'),
                'date_from' => $request->validated('dateFrom'),
                'date_to' => $request->validated('dateTo'),
                'has_voided_payment' => $request->validated('hasVoidedPayment'),
                'has_recovery_pending' => $request->validated('hasRecoveryPending'),
            ]
        );

        return response()->json([
            'data' => OrderResource::collection($orders->getCollection()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'currentPage' => $orders->currentPage(),
                'perPage' => $orders->perPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'lastPage' => $orders->lastPage(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $result = $this->orderService->createOrder(
            CreateOrderData::fromArray($request->validated()),
            $this->resolveAuthenticatedUser($request),
        );

        $message = $result->meta !== null
            ? 'Existing open bill resumed.'
            : 'Order created successfully.';

        return response()->json([
            'message' => $message,
            'data' => new OrderResource($result->order),
            'meta' => $result->meta,
        ], $result->httpStatus());
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $orderData = $this->orderService->findScoped(
            $this->resolveAuthenticatedUser($request),
            $order,
        );
        abort_if($orderData === null, Response::HTTP_NOT_FOUND, 'Order not found');

        return response()->json([
            'data' => new OrderResource($orderData),
        ]);
    }

    public function update(UpdateOrderRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $updated = $this->orderService->updateOrder(
            $user,
            $order,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Order updated successfully.',
            'data' => new OrderResource($updated),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $order): JsonResponse
    {
        $updated = $this->orderService->updateStatus(
            $this->resolveAuthenticatedUser($request),
            $order,
            (string) $request->validated('status')
        );
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Order not found');

        return response()->json([
            'message' => 'Order status updated successfully.',
            'data' => new OrderResource($updated),
        ]);
    }

    public function setMember(SetOrderMemberRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $memberId = $request->validated('memberId');
        $updated = $this->orderService->setOrderMember(
            $user,
            $order,
            $memberId !== null ? (int) $memberId : null,
        );

        return response()->json([
            'message' => 'Order member updated successfully.',
            'data' => new OrderResource($updated),
        ]);
    }

    public function addPayments(AddOrderPaymentsRequest $request, int $order): JsonResponse
    {
        $updated = $this->orderService->addPayments(
            $this->resolveAuthenticatedUser($request),
            $order,
            $request->validated('payments', []),
            $request->validated('cashAccountCode'),
            $request->validated('revenueAccountCode'),
            $request->validated('idempotencyKey') ?? $request->header('Idempotency-Key'),
            $request->validated('expectedUpdatedAt'),
            isset($request->validated()['qrOrderRequestId'])
                ? (int) $request->validated('qrOrderRequestId')
                : null,
        );
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Order not found');

        return response()->json([
            'message' => 'Order payments recorded successfully.',
            'data' => new OrderResource($updated),
        ]);
    }

    public function listPayments(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $payments = $this->paymentAllocationService->listPayments($user, $order);

        return response()->json([
            'data' => $payments->values()->all(),
        ]);
    }

    public function listEvents(Request $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $events = $this->orderService->listPosEventsForOrder($user, $order);

        return response()->json([
            'data' => PosEventLogResource::collection($events),
        ]);
    }

    public function storeSplit(StoreOrderSplitRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $split = $this->splitBillService->createSplit(
            $user,
            $order,
            $request->validated(),
            $request->validated('idempotencyKey') ?? $request->header('Idempotency-Key'),
            $request->validated('expectedUpdatedAt')
        );

        return response()->json([
            'message' => 'Order split created successfully.',
            'data' => new OrderSplitResource($split),
        ], Response::HTTP_CREATED);
    }

    public function syncSplits(SyncOrderSplitsRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $validated = $request->validated();
        $splits = $this->splitBillService->syncSplits(
            $user,
            $order,
            $validated['persons'],
            $validated['idempotencyKey'] ?? $request->header('Idempotency-Key'),
            $validated['expectedUpdatedAt'] ?? null,
        );

        return response()->json([
            'message' => 'Order splits synced successfully.',
            'data' => OrderSplitResource::collection($splits),
        ]);
    }

    public function kitchenReprint(KitchenReprintRequest $request, int $order): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $orderModel = Order::query()->findOrFail($order);
        $result = $this->kitchenReprintService->reprintItems(
            $user,
            $orderModel,
            $request->validated('orderItemIds'),
        );

        return response()->json([
            'message' => 'Kitchen reprint queued successfully.',
            'data' => $result,
        ]);
    }

    public function updateSplit(UpdateOrderSplitRequest $request, int $order, int $split): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $updated = $this->splitBillService->updateSplit(
            $user,
            $order,
            $split,
            $request->validated(),
            $request->validated('idempotencyKey') ?? $request->header('Idempotency-Key'),
            $request->validated('expectedUpdatedAt')
        );

        return response()->json([
            'message' => 'Order split updated successfully.',
            'data' => new OrderSplitResource($updated),
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }

    public function closeShift(ShiftClosePostingRequest $request): JsonResponse
    {
        $user = $this->resolveAuthenticatedUser($request);
        $outletId = (int) $request->validated('outletId');

        $result = app(\App\Modules\ShiftClose\Services\ShiftCloseEngineService::class)->run(
            $request->validated('tenantId') !== null ? (int) $request->validated('tenantId') : null,
            $outletId,
            $user,
            true,
            false,
        );

        return response()->json([
            'message' => 'Shift close posting completed successfully.',
            'data' => $result,
        ]);
    }
}
