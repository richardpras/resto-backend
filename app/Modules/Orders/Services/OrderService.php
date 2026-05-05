<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderPaymentAllocation;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Modules\Inventory\Services\RecipeStockDeductionService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RecipeStockDeductionService $recipeStockDeductionService,
    ) {}

    public function listByTenant(int $tenantId, int $perPage = 20, array $filters = [])
    {
        return $this->orderRepository->paginateByTenant($tenantId, $perPage, $filters);
    }

    public function create(CreateOrderData $data)
    {
        return DB::transaction(function () use ($data) {
            $normalizedPayments = $this->normalizePayments($data->payments);
            $paidTotal = collect($normalizedPayments)->sum(fn (array $payment): float => (float) $payment['amount']);
            $paymentStatus = $paidTotal >= $data->total ? 'paid' : ($paidTotal > 0 ? 'partial' : 'unpaid');
            $status = $paymentStatus === 'paid' && $data->status !== 'cancelled' ? 'completed' : $data->status;

            $order = $this->orderRepository->create([
                'tenant_id' => $data->tenantId,
                'outlet_id' => $data->outletId,
                'code' => $data->code,
                'source' => $data->source,
                'order_type' => $data->orderType,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'subtotal' => $data->subtotal,
                'tax' => $data->tax,
                'total' => $data->total,
                'discount_amount' => $data->discountAmount,
                'paid_total' => $paidTotal,
                'balance_due' => max(0, $data->total - $paidTotal),
                'customer_name' => $data->customerName,
                'customer_phone' => $data->customerPhone,
                'table_number' => $data->tableNumber,
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
                $this->createPrintJob($order->id, 'kitchen');
            }

            if ($paymentStatus === 'paid') {
                $this->recipeStockDeductionService->deductForPaidOrder($order);
                $this->createPrintJob($order->id, 'receipt');
            }

            return $this->orderRepository->findWithRelations($order->id);
        });
    }

    public function find(int $id)
    {
        return $this->orderRepository->findWithRelations($id);
    }

    public function updateStatus(int $id, string $status)
    {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            return null;
        }

        $this->orderRepository->update($order, ['status' => $status]);

        return $this->orderRepository->findWithRelations($id);
    }

    public function addPayments(
        int $id,
        array $payments,
        ?string $cashAccountCode = null,
        ?string $revenueAccountCode = null
    ) {
        return DB::transaction(function () use ($id, $payments) {
            $order = $this->orderRepository->findById($id);
            if ($order === null) {
                return null;
            }

            $normalizedPayments = $this->normalizePayments($payments);
            $this->storePayments($order->id, $normalizedPayments);

            $paidTotal = (float) Payment::query()->where('order_id', $order->id)->sum('amount');
            $paymentStatus = $paidTotal >= (float) $order->total ? 'paid' : ($paidTotal > 0 ? 'partial' : 'unpaid');
            $status = $paymentStatus === 'paid' && $order->status !== 'cancelled' ? 'completed' : $order->status;

            $this->orderRepository->update($order, [
                'status' => $status,
                'payment_status' => $paymentStatus,
                'paid_total' => $paidTotal,
                'balance_due' => max(0, (float) $order->total - $paidTotal),
            ]);

            if ($paymentStatus === 'paid') {
                $this->recipeStockDeductionService->deductForPaidOrder($order->fresh(['items']));
                $this->createPrintJob($order->id, 'receipt');
            }

            return $this->orderRepository->findWithRelations($order->id);
        });
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

            $journal = Journal::query()->create([
                'tenant_id' => $tenantId,
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

    private function createPrintJob(int $orderId, string $type): void
    {
        PrintJob::query()->create([
            'source_type' => 'order',
            'source_id' => $orderId,
            'type' => $type,
            'content' => ['orderId' => $orderId, 'type' => $type],
            'status' => 'pending',
        ]);
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
}
