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
    public function __construct(
        private readonly PromotionEvaluationService $promotionEvaluationService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderTaxResolverService $taxResolverService,
    ) {}

    /**
     * @return array{
     *     subtotal: float,
     *     discount: float,
     *     subtotalAfterDiscount: float,
     *     tax: float,
     *     total: float,
     *     balanceDue: float,
     *     taxLines: list<array<string, mixed>>
     * }
     */
    public function buildPreview(Order $order): array
    {
        $subtotal = (float) $order->subtotal;
        $discount = $this->resolveCheckoutDiscount($order);
        $taxResult = $this->taxResolverService->resolve(
            outletId: $order->outlet_id !== null ? (int) $order->outlet_id : null,
            serviceMode: $order->service_mode,
            orderType: $order->order_type,
            subtotal: $subtotal,
            discount: $discount,
            applyTax: (bool) ($order->apply_tax ?? false),
        );
        $paid = (float) ($order->paid_total ?? 0);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'subtotalAfterDiscount' => $taxResult['subtotalAfterDiscount'],
            'tax' => $taxResult['tax'],
            'total' => $taxResult['total'],
            'balanceDue' => max(0.0, round($taxResult['total'] - $paid, 2)),
            'taxLines' => $taxResult['taxLines'],
        ];
    }

    public function syncOrderFinancials(Order $order): Order
    {
        $preview = $this->buildPreview($order);

        $order->update([
            'discount_amount' => $preview['discount'],
            'tax' => $preview['tax'],
            'total' => $preview['total'],
            'balance_due' => $preview['balanceDue'],
            'tax_snapshot' => $preview['taxLines'] !== [] ? $preview['taxLines'] : null,
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

        return (float) ($order->discount_amount ?? 0);
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
