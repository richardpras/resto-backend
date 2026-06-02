<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderPaymentAllocation;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use App\Modules\Kitchen\Services\KitchenTicketService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Events\OrderLifecycleChanged;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Payments\Events\PaymentStatusChanged;
use App\Modules\Print\Services\PrinterRoutingService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RecipeStockDeductionService $recipeStockDeductionService,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly KitchenTicketService $kitchenTicketService,
        private readonly OptimisticConcurrencyService $optimisticConcurrencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly JournalPostingService $journalPostingService,
        private readonly PrinterRoutingService $printerRoutingService,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     */
    public function listOrders(?User $user, int $tenantId, int $perPage, array $filters)
    {
        $allowed = $user !== null ? $this->outletAccessResolver->allowedOutletIds($user) : null;

        $requestedOutletId = isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;
        if ($requestedOutletId !== null && $requestedOutletId > 0 && $allowed !== null) {
            if (! in_array($requestedOutletId, $allowed, true)) {
                throw ValidationException::withMessages([
                    'outletId' => ['The selected outletId is invalid.'],
                ]);
            }
        }

        if ($allowed !== null) {
            $filters['allowed_outlet_ids'] = $allowed;
        }

        return $this->orderRepository->paginateByTenant($tenantId, $perPage, $filters);
    }

    /** @deprecated Prefer {@see self::listOrders()} which is outlet-scoped. */
    public function listByTenant(int $tenantId, int $perPage = 20, array $filters = [])
    {
        return $this->orderRepository->paginateByTenant($tenantId, $perPage, $filters);
    }

    public function findScoped(?User $user, int $orderId): ?Order
    {
        if ($user === null) {
            return $this->orderRepository->findWithRelations($orderId);
        }
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return $this->orderRepository->findScoped($orderId, $allowed);
    }

    /**
     * Operational audit timeline for an order (POS event log rows scoped to the order and its splits).
     *
     * @return Collection<int, PosEventLog>
     */
    public function listPosEventsForOrder(?User $user, int $orderId): Collection
    {
        $order = $this->findScoped($user, $orderId);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return PosEventLog::query()
            ->where('outlet_id', $order->outlet_id)
            ->where(function ($query) use ($orderId): void {
                $query->where(function ($q) use ($orderId): void {
                    $q->where('entity_type', 'order')->where('entity_id', $orderId);
                })->orWhere(function ($q) use ($orderId): void {
                    $q->where('entity_type', 'order_split')->where('payload->orderId', $orderId);
                });
            })
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();
    }

    public function create(CreateOrderData $data, ?User $user = null)
    {
        if ($user !== null && $data->outletId !== null) {
            $this->assertOutletAllowed($user, (int) $data->outletId);
        }

        $explicitServiceMode = $data->serviceMode !== null;
        $serviceMode = $this->resolveServiceMode($data);
        $orderChannel = $this->resolveOrderChannel($data, $serviceMode);
        $posSessionId = $this->resolvePosSessionId($data, $serviceMode, $explicitServiceMode);

        if ($explicitServiceMode && $serviceMode === 'dine_in' && $data->tableId === null) {
            throw ValidationException::withMessages([
                'tableId' => ['Dine-in orders require a table.'],
            ]);
        }

        return DB::transaction(function () use ($data, $serviceMode, $orderChannel, $posSessionId, $user) {
            $normalizedPayments = $this->normalizePayments($data->payments);
            $paidTotal = collect($normalizedPayments)->sum(fn (array $payment): float => (float) $payment['amount']);
            if ($paidTotal > ((float) $data->total + 0.00001)) {
                throw ValidationException::withMessages([
                    'payments' => ['Total allocated payment cannot exceed order total.'],
                ]);
            }
            $paymentStatus = $paidTotal >= $data->total ? 'paid' : ($paidTotal > 0 ? 'partial' : 'unpaid');
            $status = $paymentStatus === 'paid' && $data->status !== 'cancelled' ? 'completed' : $data->status;

            [$floorTableId, $floorTableName] = $this->resolveFloorTableForOrder($data);

            $order = $this->orderRepository->create([
                'tenant_id' => $data->tenantId,
                'outlet_id' => $data->outletId,
                'pos_session_id' => $posSessionId,
                'code' => $data->code,
                'source' => $data->source,
                'order_channel' => $orderChannel,
                'service_mode' => $serviceMode,
                'order_type' => $data->orderType,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'kitchen_status' => 'queued',
                'subtotal' => $data->subtotal,
                'tax' => $data->tax,
                'total' => $data->total,
                'discount_amount' => $data->discountAmount,
                'paid_total' => $paidTotal,
                'balance_due' => max(0, $data->total - $paidTotal),
                'customer_name' => $data->customerName,
                'customer_phone' => $data->customerPhone,
                'table_id' => $floorTableId,
                'table_name' => $floorTableName,
                'split_bill' => $data->splitBill,
                'created_at' => $data->createdAt,
                'confirmed_at' => $data->confirmedAt,
                'is_posted' => false,
            ]);

            foreach ($data->items as $item) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'item_id' => $item['id'],
                    'name' => $item['name'],
                    'emoji' => $item['emoji'] ?? null,
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'line_total' => (float) $item['qty'] * (float) $item['price'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $this->storePayments($order->id, $normalizedPayments);

            if ($status === 'confirmed' || $status === 'completed') {
                $this->printerRoutingService->queueKitchenTicketsForOrder($order->fresh(['items']));
                $this->kitchenTicketService->createFromOrder($order->fresh(['items']));
            }

            if ($paymentStatus === 'paid') {
                $this->recipeStockDeductionService->deductForPaidOrder($order);
                $this->postOrderPaymentJournal($order->fresh(['payments']));
                $this->printerRoutingService->queueReceiptForOrder($order->fresh(['items']), 'order-paid');
            }

            $this->auditLogService->log(
                'order.created',
                'order',
                (int) $order->id,
                (int) ($order->outlet_id ?? 0),
                $user,
                ['status' => (string) $order->status, 'paymentStatus' => (string) $order->payment_status]
            );
            event(new OrderLifecycleChanged(
                outletId: (int) ($order->outlet_id ?? 0),
                orderId: (int) $order->id,
                status: (string) $order->status,
                paymentStatus: (string) $order->payment_status,
                kitchenStatus: (string) $order->kitchen_status,
                sequence: (int) $order->id,
                aggregateUpdatedAtIso: $order->updated_at?->toIso8601String()
            ));

            return $this->orderRepository->findWithRelations($order->id);
        });
    }

    /**
     * Phase 2 lifecycle alias — explicit outlet-scoped order creation.
     */
    public function createOrder(User $user, CreateOrderData $data): ?Order
    {
        return $this->create($data, $user);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateOrder(User $user, int $orderId, array $payload): Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return DB::transaction(function () use ($orderId, $payload, $allowed, $user): Order {
            $order = $this->orderRepository->findScoped($orderId, $allowed);
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->validateEditableOrder($order);
            $this->optimisticConcurrencyService->assertNotStale(
                $order,
                isset($payload['expectedUpdatedAt']) ? (string) $payload['expectedUpdatedAt'] : null
            );

            if (array_key_exists('items', $payload) && is_array($payload['items'])) {
                OrderItem::query()->where('order_id', $order->id)->delete();
                foreach ($payload['items'] as $item) {
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'item_id' => $item['id'],
                        'name' => $item['name'],
                        'emoji' => $item['emoji'] ?? null,
                        'qty' => $item['qty'],
                        'price' => $item['price'],
                        'line_total' => (float) $item['qty'] * (float) $item['price'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            $attributes = [];
            foreach (['subtotal', 'tax', 'total'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $attributes[$field] = (float) $payload[$field];
                }
            }
            if (array_key_exists('discountAmount', $payload)) {
                $attributes['discount_amount'] = (float) $payload['discountAmount'];
            }
            if (array_key_exists('customerName', $payload)) {
                $attributes['customer_name'] = $payload['customerName'];
            }
            if (array_key_exists('customerPhone', $payload)) {
                $attributes['customer_phone'] = $payload['customerPhone'];
            }
            if (array_key_exists('total', $payload)) {
                $attributes['balance_due'] = max(0, (float) $payload['total'] - (float) $order->paid_total);
            }

            if ($attributes !== []) {
                $this->orderRepository->update($order, $attributes);
            }

            $fresh = $this->orderRepository->findWithRelations($order->id);
            if ($fresh === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->auditLogService->log(
                'order.updated',
                'order',
                (int) $fresh->id,
                (int) ($fresh->outlet_id ?? 0),
                $user,
                ['fields' => array_keys($attributes)]
            );
            event(new OrderLifecycleChanged(
                outletId: (int) ($fresh->outlet_id ?? 0),
                orderId: (int) $fresh->id,
                status: (string) $fresh->status,
                paymentStatus: (string) $fresh->payment_status,
                kitchenStatus: (string) $fresh->kitchen_status,
                sequence: (int) $fresh->id,
                aggregateUpdatedAtIso: $fresh->updated_at?->toIso8601String()
            ));

            return $fresh;
        });
    }

    /**
     * Attach a master floor table to an existing editable order.
     */
    public function attachTable(User $user, int $orderId, int $tableId): Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = $this->orderRepository->findScoped($orderId, $allowed);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        $this->validateEditableOrder($order);

        $table = RestaurantTable::query()
            ->whereKey($tableId)
            ->where('outlet_id', $order->outlet_id)
            ->where('status', 'active')
            ->first();
        if ($table === null) {
            throw ValidationException::withMessages([
                'tableId' => ['Table not found for this outlet or table is inactive.'],
            ]);
        }

        $this->orderRepository->update($order, [
            'table_id' => $table->id,
            'table_name' => $table->name,
            'service_mode' => 'dine_in',
        ]);

        $fresh = $this->orderRepository->findWithRelations($order->id);
        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return $fresh;
    }

    /**
     * Throws when an order is no longer editable (paid, void, cancelled).
     */
    public function validateEditableOrder(Order $order): void
    {
        $paymentStatus = (string) $order->payment_status;
        if (! in_array($paymentStatus, ['unpaid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'paymentStatus' => ['Order is no longer editable (payment status is '.$paymentStatus.').'],
            ]);
        }
        if ((string) $order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ['Cancelled orders cannot be edited.'],
            ]);
        }
    }

    public function find(int $id)
    {
        return $this->orderRepository->findWithRelations($id);
    }

    public function updateStatus(?User $user, int $id, string $status)
    {
        if ($user !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            $order = $this->orderRepository->findScoped($id, $allowed);
            if ($order === null) {
                return null;
            }
        } else {
            $order = $this->orderRepository->findById($id);
            if ($order === null) {
                return null;
            }
        }

        $this->orderRepository->update($order, ['status' => $status]);
        if (in_array($status, ['confirmed', 'completed'], true)) {
            $this->printerRoutingService->queueKitchenTicketsForOrder($order->fresh(['items']));
            $this->kitchenTicketService->createFromOrder($order->fresh(['items']));
        }
        $fresh = $this->orderRepository->findWithRelations($id);
        if ($fresh !== null) {
            event(new OrderLifecycleChanged(
                outletId: (int) ($fresh->outlet_id ?? 0),
                orderId: (int) $fresh->id,
                status: (string) $fresh->status,
                paymentStatus: (string) $fresh->payment_status,
                kitchenStatus: (string) $fresh->kitchen_status,
                sequence: (int) $fresh->id,
                aggregateUpdatedAtIso: $fresh->updated_at?->toIso8601String()
            ));
        }

        return $fresh;
    }

    public function addPayments(
        ?User $user,
        int $id,
        array $payments,
        ?string $cashAccountCode = null,
        ?string $revenueAccountCode = null,
        ?string $idempotencyKey = null,
        ?string $expectedUpdatedAt = null
    ) {
        if ($user === null) {
            throw new AuthorizationException('Unauthenticated payment mutation is not allowed.');
        }

        $before = $this->orderRepository->findScoped($id, $this->outletAccessResolver->allowedOutletIds($user));
        $updated = $this->paymentAllocationService->addPayments($user, $id, $payments, $idempotencyKey, $expectedUpdatedAt);
        if ($updated !== null && $before !== null && (string) $before->payment_status !== 'paid' && (string) $updated->payment_status === 'paid') {
            $this->recipeStockDeductionService->deductForPaidOrder($updated->fresh(['items']));
            $this->postOrderPaymentJournal($updated->fresh(['payments']));
            $this->printerRoutingService->queueReceiptForOrder($updated->fresh(['items']), 'order-paid');
        }
        if ($updated !== null) {
            event(new OrderLifecycleChanged(
                outletId: (int) ($updated->outlet_id ?? 0),
                orderId: (int) $updated->id,
                status: (string) $updated->status,
                paymentStatus: (string) $updated->payment_status,
                kitchenStatus: (string) $updated->kitchen_status,
                sequence: (int) $updated->id,
                aggregateUpdatedAtIso: $updated->updated_at?->toIso8601String()
            ));
            event(new PaymentStatusChanged(
                outletId: (int) ($updated->outlet_id ?? 0),
                transactionId: (int) $updated->id,
                orderId: (int) $updated->id,
                status: (string) $updated->payment_status,
                provider: 'pos_allocation',
                sequence: (int) $updated->id,
                aggregateUpdatedAtIso: $updated->updated_at?->toIso8601String()
            ));
        }

        return $updated;
    }

    public function closeShiftAndPostJournal(
        ?int $tenantId = null,
        ?int $outletId = null,
        ?string $cashAccountCode = null,
        ?string $revenueAccountCode = null,
        ?string $cogsAccountCode = null,
        ?string $inventoryAccountCode = null
    ): array {
        return DB::transaction(function () use ($tenantId, $outletId, $cashAccountCode, $revenueAccountCode, $cogsAccountCode, $inventoryAccountCode): array {
            $orders = Order::query()
                ->where('payment_status', 'paid')
                ->where('is_posted', false)
                ->when($tenantId !== null && $tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
                ->when($outletId !== null && $outletId > 0, fn ($query) => $query->where('outlet_id', $outletId))
                ->lockForUpdate()
                ->with('items:id,order_id,item_id,qty')
                ->get(['id', 'code', 'tenant_id', 'outlet_id', 'total', 'paid_total']);

            if ($orders->isEmpty()) {
                return [
                    'orderCount' => 0,
                    'totalSales' => 0.0,
                    'totalCogs' => 0.0,
                    'journalId' => null,
                ];
            }

            $totalSales = (float) $orders->sum(fn (Order $order): float => (float) $order->paid_total);
            $totalCogs = $this->calculateCogsForOrders($orders);

            $cashAccount = $this->resolveAccount($cashAccountCode, ['1100', '1001'], ['asset']);
            $revenueAccount = $this->resolveAccount($revenueAccountCode, ['4100', '4001'], ['revenue']);
            $cogsAccount = $this->resolveAccount($cogsAccountCode, ['5100'], ['expense'], 'cogs');
            $inventoryAccount = $this->resolveAccount($inventoryAccountCode, ['1300'], ['asset']);

            if ($cashAccount === null || $revenueAccount === null || $cogsAccount === null || $inventoryAccount === null) {
                throw ValidationException::withMessages([
                    'accounts' => ['Shift close posting requires mapped Cash, Revenue, COGS, and Inventory accounts.'],
                ]);
            }

            $journalOutletId = $this->resolveShiftCloseJournalOutletId($outletId, $orders);

            $journal = Journal::query()->create([
                'tenant_id' => $tenantId,
                'outlet_id' => $journalOutletId,
                'journal_no' => 'JRN-SHIFT-'.now()->format('YmdHis'),
                'source_type' => 'shift_close',
                'source_id' => now()->format('YmdHis'),
                'journal_date' => now()->toDateString(),
                'status' => 'posted',
                'description' => 'POS shift close posting',
            ]);

            JournalEntry::query()->create([
                'journal_id' => $journal->id,
                'account_id' => $cashAccount->id,
                'debit' => $totalSales,
                'credit' => 0,
                'memo' => 'Cash received from paid POS orders',
                'line_no' => 1,
            ]);
            JournalEntry::query()->create([
                'journal_id' => $journal->id,
                'account_id' => $revenueAccount->id,
                'debit' => 0,
                'credit' => $totalSales,
                'memo' => 'Revenue recognized on shift close',
                'line_no' => 2,
            ]);
            JournalEntry::query()->create([
                'journal_id' => $journal->id,
                'account_id' => $cogsAccount->id,
                'debit' => $totalCogs,
                'credit' => 0,
                'memo' => 'COGS recognized on shift close',
                'line_no' => 3,
            ]);
            JournalEntry::query()->create([
                'journal_id' => $journal->id,
                'account_id' => $inventoryAccount->id,
                'debit' => 0,
                'credit' => $totalCogs,
                'memo' => 'Inventory reduction on shift close',
                'line_no' => 4,
            ]);

            Order::query()
                ->whereIn('id', $orders->pluck('id')->all())
                ->where('is_posted', false)
                ->update(['is_posted' => true]);

            return [
                'orderCount' => $orders->count(),
                'totalSales' => round($totalSales, 2),
                'totalCogs' => round($totalCogs, 2),
                'journalId' => (string) $journal->id,
            ];
        });
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function resolveServiceMode(CreateOrderData $data): ?string
    {
        if ($data->serviceMode !== null) {
            return $data->serviceMode;
        }

        return match (strtolower(trim($data->orderType))) {
            'dine-in', 'dine_in', 'dinein' => 'dine_in',
            'takeaway', 'take-away', 'take_away' => 'takeaway',
            default => null,
        };
    }

    private function resolveOrderChannel(CreateOrderData $data, ?string $serviceMode): ?string
    {
        if ($data->orderChannel !== null) {
            return $data->orderChannel;
        }
        if ($data->source === 'qr') {
            return 'qr';
        }
        if ($serviceMode === 'dine_in') {
            return 'dine_in';
        }
        if ($serviceMode === 'takeaway') {
            return 'takeaway';
        }

        return null;
    }

    private function resolvePosSessionId(CreateOrderData $data, ?string $serviceMode, bool $strict): ?int
    {
        if ($data->posSessionId !== null && $data->posSessionId > 0) {
            $session = PosSession::query()->whereKey($data->posSessionId)->first();
            if ($session === null || (string) $session->status !== 'open') {
                throw ValidationException::withMessages([
                    'posSessionId' => ['POS session is not open.'],
                ]);
            }
            if ($data->outletId !== null && (int) $session->outlet_id !== (int) $data->outletId) {
                throw ValidationException::withMessages([
                    'posSessionId' => ['POS session does not belong to the selected outlet.'],
                ]);
            }

            return (int) $session->id;
        }

        if ($serviceMode === 'dine_in' && $data->outletId !== null) {
            $session = PosSession::query()
                ->where('outlet_id', $data->outletId)
                ->where('status', 'open')
                ->latest('id')
                ->first();

            if ($session === null) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'posSessionId' => ['Dine-in orders require an open POS session for the outlet.'],
                    ]);
                }

                return null;
            }

            return (int) $session->id;
        }

        return null;
    }

    private function normalizePayments(array $payments): array
    {
        return array_map(function (array $payment): array {
            return [
                'method' => $this->normalizePaymentMethod((string) ($payment['method'] ?? '')),
                'amount' => (float) ($payment['amount'] ?? 0),
                'paidAt' => $payment['paidAt'] ?? null,
                'splitBillLabel' => $payment['splitBillLabel'] ?? null,
                'splitBillGroup' => $payment['splitBillGroup'] ?? null,
                'allocations' => collect($payment['allocations'] ?? [])->map(fn (array $allocation): array => [
                    'orderItemId' => (int) ($allocation['orderItemId'] ?? 0),
                    'qty' => (float) ($allocation['qty'] ?? 0),
                    'amount' => (float) ($allocation['amount'] ?? 0),
                ])->values()->all(),
            ];
        }, $payments);
    }

    private function normalizePaymentMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'cash' => 'cash',
            'qris', 'qr', 'qr code' => 'qris',
            'e-wallet', 'ewallet' => 'ewallet',
            'card', 'credit card', 'debit card' => 'card',
            default => 'transfer',
        };
    }

    private function storePayments(int $orderId, array $payments): void
    {
        $orderItems = OrderItem::query()
            ->where('order_id', $orderId)
            ->get(['id', 'qty'])
            ->keyBy('id');

        $existingAllocatedQtyByOrderItem = OrderPaymentAllocation::query()
            ->selectRaw('order_item_id, SUM(qty) as qty')
            ->whereHas('payment', fn ($query) => $query->where('order_id', $orderId))
            ->groupBy('order_item_id')
            ->pluck('qty', 'order_item_id')
            ->map(fn ($qty) => (float) $qty);

        $runningAllocatedQtyByOrderItem = [];

        foreach ($payments as $payment) {
            $allocations = $payment['allocations'] ?? [];
            $this->validatePaymentAllocations($payment, $allocations, $orderItems, $existingAllocatedQtyByOrderItem, $runningAllocatedQtyByOrderItem);

            $storedPayment = Payment::query()->create([
                'order_id' => $orderId,
                'method' => $payment['method'],
                'amount' => $payment['amount'],
                'split_bill_label' => $payment['splitBillLabel'],
                'split_bill_group' => $payment['splitBillGroup'],
                'paid_at' => $payment['paidAt'] ?? now(),
            ]);

            foreach ($allocations as $allocation) {
                OrderPaymentAllocation::query()->create([
                    'payment_id' => $storedPayment->id,
                    'order_item_id' => $allocation['orderItemId'],
                    'qty' => $allocation['qty'],
                    'amount' => $allocation['amount'],
                ]);
            }
        }
    }

    private function validatePaymentAllocations(
        array $payment,
        array $allocations,
        Collection $orderItems,
        Collection $existingAllocatedQtyByOrderItem,
        array &$runningAllocatedQtyByOrderItem
    ): void {
        $paymentAmount = (float) $payment['amount'];
        $allocationAmount = collect($allocations)->sum(fn (array $allocation): float => (float) $allocation['amount']);
        if ($allocations !== [] && abs($allocationAmount - $paymentAmount) > 0.01) {
            throw ValidationException::withMessages([
                'payments' => ['Allocation amount must match payment amount for each payment.'],
            ]);
        }

        foreach ($allocations as $allocation) {
            $orderItemId = (int) $allocation['orderItemId'];
            $orderItem = $orderItems->get($orderItemId);
            if ($orderItem === null) {
                throw ValidationException::withMessages([
                    'payments' => ['Allocation order item is not part of this order.'],
                ]);
            }

            $allocatedQty = (float) $allocation['qty'];
            $previousQty = (float) ($existingAllocatedQtyByOrderItem->get($orderItemId, 0))
                + (float) ($runningAllocatedQtyByOrderItem[$orderItemId] ?? 0);
            $maxQty = (float) $orderItem->qty;
            if (($previousQty + $allocatedQty) > ($maxQty + 0.00001)) {
                throw ValidationException::withMessages([
                    'payments' => ['Allocation qty exceeds order item qty.'],
                ]);
            }

            $runningAllocatedQtyByOrderItem[$orderItemId] = ($runningAllocatedQtyByOrderItem[$orderItemId] ?? 0) + $allocatedQty;
        }
    }

    /**
     * Resolve snapshot fields for POS master table (`table_id` + `table_name`).
     * `table_number` is legacy/read-only — new orders never write it via this flow.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveFloorTableForOrder(CreateOrderData $data): array
    {
        if ($data->tableId !== null) {
            if ($data->outletId === null || $data->outletId < 1) {
                throw ValidationException::withMessages([
                    'tableId' => ['outletId is required when selecting a floor table.'],
                ]);
            }
            $row = RestaurantTable::query()
                ->whereKey($data->tableId)
                ->where('outlet_id', $data->outletId)
                ->where('status', 'active')
                ->first();
            if ($row === null) {
                throw ValidationException::withMessages([
                    'tableId' => ['Table not found for this outlet or table is inactive.'],
                ]);
            }

            return [(int) $row->id, (string) $row->name];
        }

        if ($data->tableNumber !== null && trim($data->tableNumber) !== '') {
            return [null, trim($data->tableNumber)];
        }

        return [null, null];
    }

    /**
     * Align shift-close journal outlet with the batch being posted: explicit filter wins,
     * otherwise a single distinct outlet among the orders.
     */
    private function resolveShiftCloseJournalOutletId(?int $requestedOutletId, Collection $orders): ?int
    {
        if ($requestedOutletId !== null && $requestedOutletId > 0) {
            return $requestedOutletId;
        }

        $distinct = $orders->pluck('outlet_id')
            ->map(fn ($v): ?int => $v !== null ? (int) $v : null)
            ->filter(fn (?int $v): bool => $v !== null && $v > 0)
            ->unique()
            ->values();

        return $distinct->count() === 1 ? (int) $distinct->first() : null;
    }

    private function calculateCogsForOrders(Collection $orders): float
    {
        $menuIds = $orders->flatMap(fn (Order $order) => $order->items->pluck('item_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($menuIds === []) {
            return 0.0;
        }

        $recipes = DB::table('menu_recipes')
            ->whereIn('menu_item_id', $menuIds)
            ->get(['menu_item_id', 'inventory_item_id', 'quantity'])
            ->groupBy('menu_item_id');
        $ingredientIds = $recipes->flatMap(fn ($rows) => collect($rows)->pluck('inventory_item_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $ingredientPrices = DB::table('ingredients')
            ->whereIn('id', $ingredientIds)
            ->pluck('price', 'id');

        $totalCogs = 0.0;
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $itemRecipes = $recipes->get((string) $item->item_id) ?? $recipes->get((int) $item->item_id);
                if ($itemRecipes === null) {
                    continue;
                }
                foreach ($itemRecipes as $recipe) {
                    $unitCost = (float) ($ingredientPrices[$recipe->inventory_item_id] ?? 0);
                    $requiredQty = (float) $item->qty * (float) $recipe->quantity;
                    $totalCogs += $requiredQty * $unitCost;
                }
            }
        }

        return $totalCogs;
    }

    private function resolveAccount(
        ?string $requestedCode,
        array $fallbackCodes,
        array $allowedTypes,
        ?string $subtype = null
    ): ?Account {
        if (is_string($requestedCode) && $requestedCode !== '') {
            $query = Account::query()->where('code', $requestedCode)->whereIn('type', $allowedTypes);
            if ($subtype !== null) {
                $query->where('subtype', $subtype);
            }

            return $query->first();
        }

        foreach ($fallbackCodes as $code) {
            $query = Account::query()->where('code', $code)->whereIn('type', $allowedTypes);
            if ($subtype !== null) {
                $query->where('subtype', $subtype);
            }
            $account = $query->first();
            if ($account !== null) {
                return $account;
            }
        }

        $query = Account::query()->whereIn('type', $allowedTypes);
        if ($subtype !== null) {
            $query->where('subtype', $subtype);
        }

        return $query->orderBy('id')->first();
    }

    private function postOrderPaymentJournal(Order $order): void
    {
        $sales = (float) $order->paid_total;
        $cogs = (float) DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->where('source_id', (string) $order->code)
            ->sum('total_cost');

        $this->journalPostingService->postForOrderPayment(
            (int) $order->id,
            (int) ($order->tenant_id ?? 0),
            $order->outlet_id !== null ? (int) $order->outlet_id : null,
            $sales,
            $cogs
        );
    }
}
