<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\QrOrderRequestItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PosSessionCloseService;
use App\Modules\Orders\Services\PosSessionService;
use App\Modules\Orders\Services\SplitBillService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WrWbPosSeeder extends Seeder
{
    private OrderService $orders;

    private SplitBillService $splits;

    private PosSessionService $sessions;

    private PosSessionCloseService $sessionClose;

  /** @var Collection<int, MenuItem> */
    private Collection $menuItems;

    private ?PosSession $posSession = null;

    public function run(): void
    {
        $this->orders = app(OrderService::class);
        $this->splits = app(SplitBillService::class);
        $this->sessions = app(PosSessionService::class);
        $this->sessionClose = app(PosSessionCloseService::class);

        $cashier = CustomerDemoContext::user('kasir1');
        $outletId = CustomerDemoContext::outletId();

        $this->menuItems = MenuItem::query()
            ->where('outlet_id', $outletId)
            ->where('available', true)
            ->orderBy('id')
            ->get();

        $this->posSession = $this->openSessionIfNeeded($cashier, $outletId, CustomerDemoContext::date(3, 8));

        $this->seedPaidOrders($cashier, $outletId);
        $this->seedOpenBill($cashier, $outletId);
        $this->seedQrOrders($outletId);
        $this->seedShiftClose($cashier, $outletId);
    }

    private function openSessionIfNeeded(User $cashier, int $outletId, \Carbon\CarbonImmutable $openedAt): PosSession
    {
        $existing = PosSession::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'open')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->sessions->open($cashier, [
            'outletId' => $outletId,
            'openingCash' => 750000,
            'openedAt' => $openedAt,
        ]);
    }

    private function seedPaidOrders(User $cashier, int $outletId): void
    {
        $table = RestaurantTable::query()->where('outlet_id', $outletId)->orderBy('id')->first();

        $scenarios = [
            ['day' => 3, 'code' => 'WRWB-POS-01', 'mode' => 'dine_in', 'items' => 2, 'payments' => [['method' => 'cash', 'amount' => null]]],
            ['day' => 6, 'code' => 'WRWB-POS-02', 'mode' => 'takeaway', 'items' => 1, 'payments' => [['method' => 'qris', 'amount' => null]]],
            ['day' => 9, 'code' => 'WRWB-POS-03', 'mode' => 'takeaway', 'items' => 2, 'payments' => [['method' => 'cash', 'share' => 0.5], ['method' => 'qris', 'share' => 0.5]]],
            ['day' => 12, 'code' => 'WRWB-POS-04', 'mode' => 'dine_in', 'items' => 2, 'split' => 2, 'payments' => [['method' => 'cash', 'split' => 0], ['method' => 'qris', 'split' => 1]]],
            ['day' => 15, 'code' => 'WRWB-POS-05', 'mode' => 'dine_in', 'items' => 3, 'splitByItem' => true, 'payments' => [['method' => 'cash', 'split' => 0], ['method' => 'cash', 'split' => 1], ['method' => 'cash', 'split' => 2]]],
            ['day' => 18, 'code' => 'WRWB-POS-06', 'mode' => 'dine_in', 'items' => 2, 'partial' => true, 'payments' => [['method' => 'cash', 'share' => 0.5]]],
            ['day' => 21, 'code' => 'WRWB-POS-07', 'mode' => 'takeaway', 'items' => 1, 'payments' => [['method' => 'qris', 'amount' => null]]],
            ['day' => 24, 'code' => 'WRWB-POS-08', 'mode' => 'takeaway', 'items' => 2, 'payments' => [['method' => 'cash', 'amount' => null]]],
            ['day' => 27, 'code' => 'WRWB-POS-09', 'mode' => 'dine_in', 'items' => 2, 'splitByItem' => true, 'payments' => [['method' => 'cash', 'split' => 0], ['method' => 'cash', 'split' => 1]]],
            ['day' => 30, 'code' => 'WRWB-POS-10', 'mode' => 'takeaway', 'items' => 2, 'discount' => 5000, 'payments' => [['method' => 'cash', 'amount' => null]]],
        ];

        foreach ($scenarios as $spec) {
            $this->createScenarioOrder($cashier, $outletId, $table, $spec);
        }
    }

    /** @param array<string,mixed> $spec */
    private function createScenarioOrder(User $cashier, int $outletId, ?RestaurantTable $table, array $spec): void
    {
        if (Order::query()->where('code', $spec['code'])->exists()) {
            return;
        }

        $when = CustomerDemoContext::date((int) $spec['day'], 12, 30);
        $lineCount = (int) ($spec['items'] ?? 1);
        $lines = $this->buildLines($lineCount);
        $subtotal = $lines['lineTotal'];
        $discount = (float) ($spec['discount'] ?? 0);
        $total = max(0, $subtotal - $discount);

        $order = $this->orders->create(CreateOrderData::fromArray([
            'tenantId' => CustomerDemoContext::TENANT_ID,
            'outletId' => $outletId,
            'code' => $spec['code'],
            'source' => 'pos',
            'orderType' => $spec['mode'] === 'dine_in' ? 'Dine In' : 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => $spec['mode'],
            'orderChannel' => $spec['mode'],
            'posSessionId' => (int) $this->posSession?->id,
            'tableId' => $spec['mode'] === 'dine_in' ? $table?->id : null,
            'tableNumber' => $spec['mode'] === 'dine_in' ? $table?->name : null,
            'items' => $lines['items'],
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $total,
            'discountAmount' => $discount,
            'payments' => [],
            'createdAt' => $when->toIso8601String(),
            'confirmedAt' => $when->addMinutes(2)->toIso8601String(),
        ]), $cashier);

        $order->refresh();
        $when = CustomerDemoContext::date((int) $spec['day'], 12, 30);
        $total = (float) $order->total;

        $splitIds = [];
        if (! empty($spec['split'])) {
            $splitIds = $this->createTwoWaySplit($cashier, $order, $lines['orderItemIds']);
        } elseif (! empty($spec['splitByItem'])) {
            $splitIds = $this->createByItemSplits($cashier, $order, $lines['orderItemIds']);
        }

        if (! empty($spec['partial'])) {
            $partialAmount = round($total * 0.5, 2);
            $this->orders->addPayments($cashier, (int) $order->id, [
                ['method' => 'cash', 'amount' => $partialAmount],
            ]);
            $this->backdateOrder($order->fresh(), $when);

            return;
        }

        $this->backdateOrder($order, $when);

        $payments = [];
        foreach ($spec['payments'] as $payment) {
            $row = ['method' => $payment['method']];
            if (isset($payment['split']) && isset($splitIds[(int) $payment['split']])) {
                $row['orderSplitId'] = $splitIds[(int) $payment['split']];
            }
            if (isset($payment['share'])) {
                $row['share'] = (float) $payment['share'];
            }
            if (isset($payment['amount'])) {
                $row['amount'] = (float) $payment['amount'];
            }
            $payments[] = $row;
        }

        if ($payments !== []) {
            $order->refresh()->load(['items', 'splits.items']);
            $payments = $this->normalizePaymentAmounts($order, $payments, $total);
            foreach ($payments as $paymentRow) {
                $payload = [
                    'method' => $paymentRow['method'],
                    'amount' => $paymentRow['amount'],
                ];
                if (! empty($paymentRow['orderSplitId'])) {
                    $payload['orderSplitId'] = (int) $paymentRow['orderSplitId'];
                }
                $this->orders->addPayments($cashier, (int) $order->id, [$payload]);
                $order->refresh();
            }
        }

        $this->backdateOrder($order->fresh(), $when);
    }

    /** @return array{items:list<array<string,mixed>>, orderItemIds:list<int>, lineTotal:float} */
    private function buildLines(int $count): array
    {
        $items = [];
        $orderItemIds = [];

        for ($i = 0; $i < $count; $i++) {
            $menu = $this->menuItems->get($i % max(1, $this->menuItems->count()));
            $items[] = [
                'id' => (string) $menu->id,
                'name' => $menu->name,
                'qty' => 1,
                'price' => (float) $menu->price,
            ];
        }

        $lineTotal = collect($items)->sum(fn (array $row): float => (float) $row['price'] * (float) $row['qty']);

        return ['items' => $items, 'orderItemIds' => $orderItemIds, 'lineTotal' => $lineTotal];
    }

    /** @param list<int> $orderItemIds */
    private function createTwoWaySplit(User $cashier, Order $order, array $orderItemIds): array
    {
        $order->load('items');
        $first = $order->items->first();
        $second = $order->items->skip(1)->first();
        if ($first === null || $second === null) {
            return [];
        }

        $splits = $this->splits->syncSplits($cashier, (int) $order->id, [
            [
                'splitType' => 'by_item',
                'label' => 'Tamu A',
                'items' => [['orderItemId' => (int) $first->id, 'qty' => 1, 'amount' => (float) $first->line_total]],
            ],
            [
                'splitType' => 'by_item',
                'label' => 'Tamu B',
                'items' => [['orderItemId' => (int) $second->id, 'qty' => 1, 'amount' => (float) $second->line_total]],
            ],
        ]);

        return $splits->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function createByItemSplits(User $cashier, Order $order, array $orderItemIds): array
    {
        $order->load('items');
        $persons = [];
        foreach ($order->items as $index => $item) {
            $persons[] = [
                'splitType' => 'by_item',
                'label' => 'Tamu '.chr(65 + $index),
                'items' => [['orderItemId' => (int) $item->id, 'qty' => 1, 'amount' => (float) $item->line_total]],
            ];
        }

        $splits = $this->splits->syncSplits($cashier, (int) $order->id, $persons);

        return $splits->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function seedOpenBill(User $cashier, int $outletId): void
    {
        $code = 'WRWB-OPEN-01';
        if (Order::query()->where('code', $code)->exists()) {
            return;
        }

        $when = CustomerDemoContext::date(28, 18);
        $lines = $this->buildLines(2);
        $total = $lines['lineTotal'];
        $table = RestaurantTable::query()->where('outlet_id', $outletId)->orderByDesc('id')->first();

        $order = $this->orders->create(CreateOrderData::fromArray([
            'tenantId' => CustomerDemoContext::TENANT_ID,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $this->posSession?->id,
            'tableId' => $table?->id,
            'items' => $lines['items'],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [],
        ]), $cashier);

        $order->update([
            'balance_due' => $total,
            'paid_total' => 0,
            'confirmed_at' => $when,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    private function seedQrOrders(int $outletId): void
    {
        $table = RestaurantTable::query()->where('outlet_id', $outletId)->where('qr_enabled', true)->first();
        $menu = $this->menuItems->first();
        if ($table === null || $menu === null) {
            return;
        }

        $pending = QrOrderRequest::query()->updateOrCreate(
            ['request_code' => 'WRWB-QR-PENDING'],
            [
                'outlet_id' => $outletId,
                'table_id' => $table->id,
                'customer_name' => 'QR Guest Pending',
                'status' => 'pending_cashier_confirmation',
                'expires_at' => CustomerDemoContext::date(26, 20)->addMinutes(30),
            ],
        );
        QrOrderRequestItem::query()->updateOrCreate(
            ['qr_order_request_id' => $pending->id, 'menu_item_id' => $menu->id],
            ['qty' => 1],
        );

        $cashier = CustomerDemoContext::user('kasir1');
        $confirmedWhen = CustomerDemoContext::date(27, 13);
        $order = $this->orders->create(CreateOrderData::fromArray([
            'tenantId' => CustomerDemoContext::TENANT_ID,
            'outletId' => $outletId,
            'code' => 'WRWB-QR-CONFIRMED',
            'source' => 'qr',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'qr',
            'tableId' => $table->id,
            'items' => [[
                'id' => (string) $menu->id,
                'name' => $menu->name,
                'qty' => 2,
                'price' => (float) $menu->price,
            ]],
            'subtotal' => (float) $menu->price * 2,
            'tax' => 0,
            'total' => (float) $menu->price * 2,
            'payments' => [],
        ]), $cashier);

        $confirmed = QrOrderRequest::query()->updateOrCreate(
            ['request_code' => 'WRWB-QR-CONFIRMED'],
            [
                'outlet_id' => $outletId,
                'table_id' => $table->id,
                'customer_name' => 'QR Guest Confirmed',
                'status' => 'confirmed',
                'order_id' => $order->id,
                'expires_at' => $confirmedWhen->addMinutes(30),
                'confirmed_at' => $confirmedWhen,
                'confirmed_by_user_id' => $cashier->id,
                'opened_in_pos_at' => $confirmedWhen,
            ],
        );
        QrOrderRequestItem::query()->updateOrCreate(
            ['qr_order_request_id' => $confirmed->id, 'menu_item_id' => $menu->id],
            ['qty' => 2],
        );

        $order->update([
            'source_type' => 'qr_order',
            'source_id' => $confirmed->id,
            'source_code' => $confirmed->request_code,
            'confirmed_at' => $confirmedWhen,
        ]);
    }

    private function seedShiftClose(User $cashier, int $outletId): void
    {
        $closedAt = CustomerDemoContext::date(31, 22);
        $session = PosSession::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'open')
            ->first();

        if ($session === null) {
            $session = $this->sessions->open($cashier, [
                'outletId' => $outletId,
                'openingCash' => 750000,
                'openedAt' => CustomerDemoContext::date(31, 7),
            ]);
        }

        $preview = $this->sessionClose->previewClose($cashier, (int) $session->id);
        $expected = (float) ($preview['drawerReconciliation']['expected'] ?? 750000);
        $actual = $expected + 12000;

        $closed = $this->sessionClose->close($cashier, (int) $session->id, [
            'actualCash' => $actual,
            'closedAt' => $closedAt,
        ]);

        $closed->update([
            'opening_cash' => 750000,
            'expected_cash' => $expected,
            'actual_cash' => $actual,
            'cash_variance' => 12000,
            'opened_at' => CustomerDemoContext::date(31, 7),
            'closed_at' => $closedAt,
        ]);
    }

    /** @param list<array<string,mixed>> $payments */
    private function normalizePaymentAmounts(Order $order, array $payments, float $total): array
    {
        $remaining = (float) $order->balance_due;
        if ($remaining <= 0) {
            $remaining = $total;
        }
        $normalized = [];

        foreach ($payments as $index => $payment) {
            $isLast = $index === count($payments) - 1;
            $amount = isset($payment['amount']) ? (float) $payment['amount'] : null;

            if ($amount === null && isset($payment['orderSplitId'])) {
                $amount = (float) DB::table('order_split_items')
                    ->where('order_split_id', (int) $payment['orderSplitId'])
                    ->sum('amount');
            }

            if (($amount === null || $amount <= 0) && isset($payment['share'])) {
                $amount = $isLast ? $remaining : round($total * (float) $payment['share'], 2);
            }

            if ($amount === null || $amount <= 0) {
                $amount = $isLast ? $remaining : $total;
            }

            $amount = min((float) $amount, $remaining);
            $remaining = round($remaining - $amount, 2);

            if ($amount > 0) {
                $normalized[] = [
                    'method' => $payment['method'],
                    'amount' => $amount,
                    'orderSplitId' => $payment['orderSplitId'] ?? null,
                ];
            }
        }

        return $normalized;
    }

    private function backdateOrder(?Order $order, \Carbon\CarbonImmutable $when): void
    {
        if ($order === null) {
            return;
        }

        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $when,
            'updated_at' => $when->addMinutes(5),
            'confirmed_at' => $when->addMinutes(2),
        ]);
    }
}
