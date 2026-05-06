<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Http\Requests\AddOrderPaymentsRequest;
use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Requests\ShiftClosePostingRequest;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $tenantId = (int) $request->validated('tenantId', 0);
        $orders = $this->orderService->listByTenant(
            $tenantId,
            (int) $request->validated('perPage', 20),
            [
                'outlet_id' => $request->validated('outletId'),
                'payment_status' => $request->validated('paymentStatus'),
                'order_type' => $request->validated('orderType'),
                'status' => $request->validated('status'),
                'source' => $request->validated('source'),
            ]
        );

        return response()->json([
            'data' => OrderResource::collection($orders->getCollection()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'perPage' => $orders->perPage(),
                'total' => $orders->total(),
                'lastPage' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create(CreateOrderData::fromArray($request->validated()));

        return response()->json([
            'message' => 'Order created successfully.',
            'data' => new OrderResource($order),
        ], Response::HTTP_CREATED);
    }

    public function show(int $order): JsonResponse
    {
        $orderData = $this->orderService->find($order);
        abort_if($orderData === null, Response::HTTP_NOT_FOUND, 'Order not found');

        return response()->json([
            'data' => new OrderResource($orderData),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $order): JsonResponse
    {
        $updated = $this->orderService->updateStatus(
            $order,
            (string) $request->validated('status')
        );
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Order not found');

        return response()->json([
            'message' => 'Order status updated successfully.',
            'data' => new OrderResource($updated),
        ]);
    }

    public function addPayments(AddOrderPaymentsRequest $request, int $order): JsonResponse
    {
        $updated = $this->orderService->addPayments(
            $order,
            $request->validated('payments', []),
            $request->validated('cashAccountCode'),
            $request->validated('revenueAccountCode')
        );
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Order not found');

        return response()->json([
            'message' => 'Order payments recorded successfully.',
            'data' => new OrderResource($updated),
        ]);
    }

    public function closeShift(ShiftClosePostingRequest $request): JsonResponse
    {
        $result = $this->orderService->closeShiftAndPostJournal(
            $request->validated('tenantId'),
            $request->validated('outletId'),
            $request->validated('cashAccountCode'),
            $request->validated('revenueAccountCode'),
            $request->validated('cogsAccountCode'),
            $request->validated('inventoryAccountCode')
        );

        return response()->json([
            'message' => 'Shift close posting completed successfully.',
            'data' => $result,
        ]);
    }
}
