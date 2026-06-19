<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderPromotion;
use App\Models\Modules\Orders\Domain\OrderVoucher;
use App\Modules\PromotionEngine\Services\PromotionEvaluationService;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;

class OrderCheckoutTotalsService
{
    public const DEFAULT_TAX_RATE = 0.10;

    public function __construct(
        private readonly PromotionEvaluationService $promotionEvaluationService,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    /**
     * @return array{
     *     subtotal: float,
     *     discount: float,
     *     subtotalAfterDiscount: float,
     *     tax: float,
     *     total: float,
     *     balanceDue: float
     * }
     */
    public function buildPreview(Order $order, ?float $taxRate = null): array
    {
        $taxRate ??= self::DEFAULT_TAX_RATE;
        $subtotal = (float) $order->subtotal;
        $discount = $this->resolveCheckoutDiscount($order);
        $subtotalAfterDiscount = max(0.0, $subtotal - $discount);
        $tax = round($subtotalAfterDiscount * $taxRate, 2);
        $total = round($subtotalAfterDiscount + $tax, 2);
        $paid = (float) ($order->paid_total ?? 0);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'subtotalAfterDiscount' => $subtotalAfterDiscount,
            'tax' => $tax,
            'total' => $total,
            'balanceDue' => max(0.0, round($total - $paid, 2)),
        ];
    }

    public function syncOrderFinancials(Order $order, ?float $taxRate = null): Order
    {
        $preview = $this->buildPreview($order, $taxRate);

        $order->update([
            'discount_amount' => $preview['discount'],
            'tax' => $preview['tax'],
            'total' => $preview['total'],
            'balance_due' => $preview['balanceDue'],
        ]);

        return $this->orderRepository->findWithRelations((int) $order->id) ?? $order->fresh() ?? $order;
    }

    private function resolveCheckoutDiscount(Order $order): float
    {
        if (! $order->relationLoaded('orderVoucher')) {
            $order->load('orderVoucher.voucher');
        }

        $orderVoucher = $order->orderVoucher;
        if ($orderVoucher !== null) {
            return $this->resolveVoucherDiscount($order, $orderVoucher);
        }

        if (! $order->relationLoaded('orderPromotion')) {
            $order->load('orderPromotion.promotion');
        }

        $orderPromotion = $order->orderPromotion;
        if ($orderPromotion !== null) {
            return $this->resolvePromotionDiscount($order, $orderPromotion);
        }

        return 0.0;
    }

    private function resolveVoucherDiscount(Order $order, OrderVoucher $orderVoucher): float
    {
        $subtotal = (float) $order->subtotal;
        if ($subtotal <= 0) {
            return 0.0;
        }

        $voucher = $orderVoucher->relationLoaded('voucher') ? $orderVoucher->voucher : null;
        if ($voucher !== null) {
            if ($voucher->value_type === LoyaltyVoucher::VALUE_PERCENTAGE) {
                $discount = round($subtotal * ((float) $voucher->value / 100), 2);

                return min($subtotal, max(0.0, $discount));
            }

            if ($voucher->value_type === LoyaltyVoucher::VALUE_FIXED_AMOUNT) {
                return min($subtotal, max(0.0, (float) $voucher->value));
            }

            return 0.0;
        }

        if ($orderVoucher->discount_type === LoyaltyVoucher::VALUE_PERCENTAGE) {
            $discount = round($subtotal * ((float) $orderVoucher->discount_value / 100), 2);

            return min($subtotal, max(0.0, $discount));
        }

        if ($orderVoucher->discount_type === LoyaltyVoucher::VALUE_FIXED_AMOUNT) {
            return min($subtotal, max(0.0, (float) $orderVoucher->discount_value));
        }

        return min($subtotal, max(0.0, (float) $orderVoucher->discount_amount));
    }

    private function resolvePromotionDiscount(Order $order, OrderPromotion $orderPromotion): float
    {
        $subtotal = (float) $order->subtotal;
        if ($subtotal <= 0) {
            return 0.0;
        }

        $promotion = $orderPromotion->promotion;
        if ($promotion !== null) {
            $cartLines = $this->buildCartLinesFromOrder($order);
            $evaluation = $this->promotionEvaluationService->calculateDiscount($promotion, $cartLines, $subtotal);

            return $evaluation['discountAmount'];
        }

        return min($subtotal, max(0.0, (float) $orderPromotion->discount_amount));
    }

    /**
     * @return list<array{id: string, name: string, price: float, qty: float, category: null}>
     */
    private function buildCartLinesFromOrder(Order $order): array
    {
        if (! $order->relationLoaded('items')) {
            $order->load('items');
        }

        return $order->items->map(fn ($item): array => [
            'id' => (string) $item->item_id,
            'name' => (string) $item->name,
            'price' => (float) $item->price,
            'qty' => (float) $item->qty,
            'category' => null,
        ])->values()->all();
    }
}
