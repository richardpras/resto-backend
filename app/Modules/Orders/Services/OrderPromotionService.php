<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderPromotion;
use App\Models\User;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\PromotionEngine\Services\PromotionEvaluationService;
use App\Modules\PromotionEngine\Services\PromotionManagementService;
use App\Modules\PromotionEngine\Services\PromotionUsageService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderPromotionService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PromotionManagementService $promotionManagementService,
        private readonly PromotionEvaluationService $promotionEvaluationService,
        private readonly PromotionUsageService $promotionUsageService,
        private readonly OrderCheckoutTotalsService $checkoutTotalsService,
    ) {}

    /**
     * @return array{order: Order, preview: array{subtotal: float, discount: float, subtotalAfterDiscount: float, tax: float, total: float, balanceDue: float}}
     */
    public function applyByCode(User $user, int $orderId, string $code): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = $this->orderRepository->findScoped($orderId, $allowed);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        $promotion = $this->promotionManagementService->findActiveByCodeForOutlet(
            $code,
            (int) $order->outlet_id,
        );

        return $this->apply($user, $orderId, (int) $promotion->id);
    }

    /**
     * @return array{order: Order, preview: array{subtotal: float, discount: float, subtotalAfterDiscount: float}}
     */
    public function apply(User $user, int $orderId, int $promotionId): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return DB::transaction(function () use ($user, $orderId, $promotionId, $allowed): array {
            $order = $this->orderRepository->findScoped($orderId, $allowed);
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->assertOrderPromotionEditable($order);

            if ($order->orderPromotion !== null) {
                throw ValidationException::withMessages([
                    'promotionId' => ['Only one promotion can be applied per order.'],
                ]);
            }

            if ($order->orderVoucher !== null) {
                throw ValidationException::withMessages([
                    'promotionId' => ['Remove the member voucher before applying a promotion.'],
                ]);
            }

            $promotion = $this->promotionManagementService->findActiveForOutlet(
                $promotionId,
                (int) $order->outlet_id,
            );

            if (! $this->promotionUsageService->hasDailyCapacity($promotion, (int) $order->outlet_id, null, (int) $order->id)) {
                throw ValidationException::withMessages([
                    'promotionId' => ['Promotion daily usage limit has been reached.'],
                ]);
            }

            $cartLines = $this->buildCartLinesFromOrder($order);
            $subtotal = (float) $order->subtotal;
            $evaluation = $this->promotionEvaluationService->calculateDiscount($promotion, $cartLines, $subtotal);

            if (! $this->promotionEvaluationService->isEligible($promotion, $cartLines, $subtotal)) {
                throw ValidationException::withMessages([
                    'promotionId' => ['Promotion is not eligible for this order.'],
                ]);
            }

            if ($evaluation['discountAmount'] <= 0) {
                throw ValidationException::withMessages([
                    'promotionId' => ['Promotion does not produce a discount for this order.'],
                ]);
            }

            OrderPromotion::query()->create([
                'order_id' => $order->id,
                'promotion_id' => $promotion->id,
                'promotion_code' => $promotion->code,
                'promotion_name' => $promotion->name,
                'discount_type' => $promotion->type,
                'discount_value' => $evaluation['discountValue'],
                'discount_amount' => $evaluation['discountAmount'],
                'applied_items' => $evaluation['appliedItems'],
                'applied_at' => now(),
            ]);

            $fresh = $this->orderRepository->findWithRelations($order->id)
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);

            $fresh = $this->checkoutTotalsService->syncOrderFinancials($fresh);

            return [
                'order' => $fresh,
                'preview' => $this->buildPreview($fresh),
            ];
        });
    }

    /**
     * @return array{order: Order, preview: array{subtotal: float, discount: float, subtotalAfterDiscount: float, tax: float, total: float, balanceDue: float}}
     */
    public function remove(User $user, int $orderId): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return DB::transaction(function () use ($orderId, $allowed): array {
            $order = $this->orderRepository->findScoped($orderId, $allowed);
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->assertOrderPromotionEditable($order);

            $orderPromotion = $order->orderPromotion;
            if ($orderPromotion === null) {
                throw ValidationException::withMessages([
                    'orderId' => ['No promotion is applied to this order.'],
                ]);
            }

            $orderPromotion->delete();

            $fresh = $this->orderRepository->findWithRelations($order->id)
                ?? throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);

            $fresh = $this->checkoutTotalsService->syncOrderFinancials($fresh);

            return [
                'order' => $fresh,
                'preview' => $this->buildPreview($fresh),
            ];
        });
    }

    /**
     * @return array{subtotal: float, discount: float, subtotalAfterDiscount: float, tax: float, total: float, balanceDue: float}
     */
    public function preview(User $user, int $orderId): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = $this->orderRepository->findScoped($orderId, $allowed);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return $this->buildPreview($order);
    }

    /**
     * @return array{subtotal: float, discount: float, subtotalAfterDiscount: float, tax: float, total: float, balanceDue: float}
     */
    public function buildPreview(Order $order): array
    {
        return $this->checkoutTotalsService->buildPreview($order);
    }

    /**
     * @return list<array{id: string, name: string, price: float, qty: float, category: string|null}>
     */
    public function buildCartLinesFromOrder(Order $order): array
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

    /**
     * Recompute or remove an applied promotion after order lines change.
     */
    public function syncAppliedPromotionForOrder(Order $order): void
    {
        if (! $order->relationLoaded('orderPromotion')) {
            $order->load('orderPromotion.promotion');
        }

        $orderPromotion = $order->orderPromotion;
        if ($orderPromotion === null) {
            return;
        }

        if (! in_array((string) $order->payment_status, ['unpaid', 'partial'], true)) {
            return;
        }

        if ((string) $order->status === 'cancelled') {
            return;
        }

        $promotion = $orderPromotion->promotion;
        if ($promotion === null || ! $promotion->is_active) {
            $orderPromotion->delete();
            $this->checkoutTotalsService->syncOrderFinancials($order);

            return;
        }

        $cartLines = $this->buildCartLinesFromOrder($order);
        $subtotal = (float) $order->subtotal;

        if (
            ! $this->promotionEvaluationService->isEligible($promotion, $cartLines, $subtotal)
            || ! $this->promotionUsageService->hasDailyCapacity($promotion, (int) $order->outlet_id, null, (int) $order->id)
        ) {
            $orderPromotion->delete();
            $this->checkoutTotalsService->syncOrderFinancials($order);

            return;
        }

        $evaluation = $this->promotionEvaluationService->calculateDiscount($promotion, $cartLines, $subtotal);
        if ($evaluation['discountAmount'] <= 0) {
            $orderPromotion->delete();
            $this->checkoutTotalsService->syncOrderFinancials($order);

            return;
        }

        $orderPromotion->update([
            'discount_type' => $promotion->type,
            'discount_value' => $evaluation['discountValue'],
            'discount_amount' => $evaluation['discountAmount'],
            'applied_items' => $evaluation['appliedItems'],
        ]);

        $this->checkoutTotalsService->syncOrderFinancials($order);
    }

    private function assertOrderPromotionEditable(Order $order): void
    {
        $paymentStatus = (string) $order->payment_status;
        if (! in_array($paymentStatus, ['unpaid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'orderId' => ['Promotion cannot be changed after payment is complete (status: '.$paymentStatus.').'],
            ]);
        }

        if ((string) $order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'orderId' => ['Cancelled orders cannot be updated.'],
            ]);
        }
    }
}
