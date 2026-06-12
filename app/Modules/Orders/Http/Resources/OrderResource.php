<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Services\OrderSourceLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderSourceLinkService $sourceLinkService */
        $sourceLinkService = app(OrderSourceLinkService::class);

        return [
            'id' => (string) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'posSessionId' => $this->pos_session_id !== null ? (int) $this->pos_session_id : null,
            'code' => $this->code,
            'source' => $this->source,
            'orderSource' => $sourceLinkService->buildOrderSource($this->resource),
            'orderChannel' => $this->order_channel,
            'serviceMode' => $this->service_mode,
            'orderType' => $this->order_type,
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'total' => (float) $this->total,
            'discountAmount' => (float) ($this->discount_amount ?? 0),
            'paymentStatus' => $this->payment_status,
            'kitchenStatus' => $this->kitchen_status ?? 'queued',
            'isPosted' => (bool) $this->is_posted,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'memberId' => $this->member_id !== null ? (int) $this->member_id : null,
            'tableId' => $this->table_id !== null ? (int) $this->table_id : null,
            'tableName' => $this->table_name,
            /** @deprecated Prefer `tableName` / master `tableId`; legacy column `table_number` read-only. */
            'tableNumber' => $this->table_name ?: $this->table_number,
            'splitBill' => $this->split_bill,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'orderItemId' => (string) $item->id,
                'id' => $item->item_id,
                'name' => $item->name,
                'emoji' => $item->emoji,
                'qty' => (float) $item->qty,
                'price' => (float) $item->price,
                'notes' => $item->notes,
                'recoveryStatus' => $item->recovery_status,
                'recoveryReason' => $item->recovery_reason,
                'recoveryApprovedAt' => $item->recovery_approved_at?->toISOString(),
                'recoveryApprovedByUserId' => $item->recovery_approved_by_user_id !== null ? (int) $item->recovery_approved_by_user_id : null,
                'replacedByOrderItemId' => $item->replaced_by_order_item_id !== null ? (int) $item->replaced_by_order_item_id : null,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'orderSplitId' => $payment->order_split_id !== null ? (int) $payment->order_split_id : null,
                'method' => $payment->method,
                'amount' => (float) $payment->amount,
                'status' => (string) ($payment->status ?? 'paid'),
                'paidAt' => $payment->paid_at?->toISOString(),
                'allocations' => $payment->relationLoaded('allocations')
                    ? $payment->allocations->map(fn ($allocation) => [
                        'orderItemId' => $allocation->order_item_id,
                        'qty' => (float) $allocation->qty,
                        'amount' => (float) $allocation->amount,
                    ])->values()
                    : [],
            ])),
            'splits' => $this->whenLoaded('splits', fn () => $this->splits->map(fn ($split) => [
                'id' => (int) $split->id,
                'splitType' => (string) $split->split_type,
                'label' => (string) $split->label,
                'status' => (string) $split->status,
                'items' => $split->relationLoaded('items')
                    ? $split->items->map(fn ($item) => [
                        'orderItemId' => (int) $item->order_item_id,
                        'qty' => (float) $item->qty,
                        'amount' => (float) $item->amount,
                    ])->values()
                    : [],
            ])->values()),
            'createdAt' => $this->created_at?->toISOString(),
            'confirmedAt' => $this->confirmed_at?->toISOString(),
            'voucher' => $this->whenLoaded('orderVoucher', fn () => $this->orderVoucher !== null
                ? new OrderVoucherResource($this->orderVoucher)
                : null),
            'voucherDiscount' => $this->when(
                $this->relationLoaded('orderVoucher'),
                fn () => $this->orderVoucher !== null
                    ? (float) $this->resolveLiveVoucherDiscount()
                    : 0.0,
            ),
            'voucherPreview' => $this->when(
                $this->relationLoaded('orderVoucher') || $this->orderVoucher !== null,
                fn () => $this->buildVoucherPreviewArray(),
            ),
        ];
    }

    private function resolveLiveVoucherDiscount(): float
    {
        $subtotal = (float) $this->subtotal;
        $orderVoucher = $this->orderVoucher;
        if ($orderVoucher === null) {
            return 0.0;
        }

        $voucher = $orderVoucher->relationLoaded('voucher') ? $orderVoucher->voucher : null;
        if ($voucher !== null) {
            if ($voucher->value_type === 'percentage') {
                return min($subtotal, max(0.0, round($subtotal * ((float) $voucher->value / 100), 2)));
            }

            return min($subtotal, max(0.0, (float) $voucher->value));
        }

        if ($orderVoucher->discount_type === 'percentage') {
            return min($subtotal, max(0.0, round($subtotal * ((float) $orderVoucher->discount_value / 100), 2)));
        }

        return min($subtotal, max(0.0, (float) $orderVoucher->discount_value));
    }

    /**
     * @return array{subtotal: float, discount: float, subtotalAfterDiscount: float}
     */
    private function buildVoucherPreviewArray(): array
    {
        $subtotal = (float) $this->subtotal;
        $discount = $this->resolveLiveVoucherDiscount();

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'subtotalAfterDiscount' => max(0.0, $subtotal - $discount),
        ];
    }
}
