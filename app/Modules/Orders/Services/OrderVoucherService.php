<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderVoucher;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\MemberVoucherLookupService;
use App\Modules\LoyaltyEngine\Services\VoucherValidationService;
use App\Modules\Members\Services\OrderMemberAttachmentService;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderVoucherService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly VoucherValidationService $voucherValidationService,
        private readonly MemberVoucherLookupService $memberVoucherLookupService,
        private readonly OrderMemberAttachmentService $orderMemberAttachmentService,
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

        $memberVoucher = $this->memberVoucherLookupService->findRedeemableByCode(
            (int) $order->outlet_id,
            $code,
        );

        if ($order->member_id === null || (int) $order->member_id < 1) {
            $this->orderMemberAttachmentService->setOrderMember(
                $user,
                $orderId,
                (int) $memberVoucher->member_id,
            );
        } elseif ((int) $order->member_id !== (int) $memberVoucher->member_id) {
            throw ValidationException::withMessages([
                'code' => ['Voucher belongs to a different member than the one on this order.'],
            ]);
        }

        return $this->apply($user, $orderId, (int) $memberVoucher->id);
    }

    /**
     * @return array{order: Order, preview: array{subtotal: float, discount: float, subtotalAfterDiscount: float}}
     */
    public function apply(User $user, int $orderId, int $memberVoucherId): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return DB::transaction(function () use ($user, $orderId, $memberVoucherId, $allowed): array {
            $order = $this->orderRepository->findScoped($orderId, $allowed);
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->assertOrderVoucherEditable($order);

            if ($order->orderVoucher !== null) {
                throw ValidationException::withMessages([
                    'memberVoucherId' => ['Only one voucher can be applied per order.'],
                ]);
            }

            if ($order->orderPromotion !== null) {
                throw ValidationException::withMessages([
                    'memberVoucherId' => ['Remove the outlet promotion before applying a member voucher.'],
                ]);
            }

            $memberVoucher = MemberVoucher::query()
                ->with('voucher')
                ->whereKey($memberVoucherId)
                ->first();

            if ($memberVoucher === null) {
                throw ValidationException::withMessages([
                    'memberVoucherId' => ['Member voucher not found.'],
                ]);
            }

            $voucher = $this->voucherValidationService->validateForOrder($order, $memberVoucher);
            $discountAmount = $this->calculateDiscount($voucher, (float) $order->subtotal);

            OrderVoucher::query()->create([
                'order_id' => $order->id,
                'member_voucher_id' => $memberVoucher->id,
                'voucher_id' => $voucher->id,
                'voucher_code' => $memberVoucher->voucher_code,
                'discount_type' => (string) $voucher->value_type,
                'discount_value' => (float) $voucher->value,
                'discount_amount' => $discountAmount,
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

        return DB::transaction(function () use ($user, $orderId, $allowed): array {
            $order = $this->orderRepository->findScoped($orderId, $allowed);
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
            }

            $this->assertOrderVoucherEditable($order);

            $orderVoucher = $order->orderVoucher;
            if ($orderVoucher === null) {
                throw ValidationException::withMessages([
                    'orderId' => ['No voucher is applied to this order.'],
                ]);
            }

            $orderVoucher->delete();

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

    public function calculateDiscount(LoyaltyVoucher $voucher, float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($voucher->value_type === LoyaltyVoucher::VALUE_PERCENTAGE) {
            $discount = round($subtotal * ((float) $voucher->value / 100), 2);

            return min($subtotal, max(0.0, $discount));
        }

        if ($voucher->value_type === LoyaltyVoucher::VALUE_FIXED_AMOUNT) {
            return min($subtotal, max(0.0, (float) $voucher->value));
        }

        return 0.0;
    }

    /**
     * @return array{subtotal: float, discount: float, subtotalAfterDiscount: float, tax: float, total: float, balanceDue: float}
     */
    public function buildPreview(Order $order): array
    {
        return $this->checkoutTotalsService->buildPreview($order);
    }

    private function assertOrderVoucherEditable(Order $order): void
    {
        $paymentStatus = (string) $order->payment_status;
        if (! in_array($paymentStatus, ['unpaid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'orderId' => ['Voucher cannot be changed after payment is complete (status: '.$paymentStatus.').'],
            ]);
        }

        if ((string) $order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'orderId' => ['Cancelled orders cannot be updated.'],
            ]);
        }
    }
}
