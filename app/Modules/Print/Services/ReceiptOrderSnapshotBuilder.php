<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\OrderSplitItem;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\User;
use App\Modules\GiftCards\Services\GiftCardAccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ReceiptOrderSnapshotBuilder
{
    public function __construct(
        private readonly ThermalReceiptLayoutBuilder $thermalReceiptLayout,
        private readonly GiftCardAccountingService $giftCardAccountingService,
    ) {}

    /**
     * @param  array{outletName:string,header:string,footer:string,showTaxBreakdown:bool,showLogo:bool,logoVersion:int,logoUrl:?string}  $receiptBranding
     * @return array<string,mixed>
     */
    public function buildFromOrder(
        Order $order,
        int $outletId,
        ?User $cashier,
        ?int $orderSplitId,
        array $receiptBranding,
    ): array {
        if ((int) $order->outlet_id !== $outletId) {
            throw ValidationException::withMessages(['sourceId' => ['Order not found for outlet.']]);
        }

        $order->loadMissing([
            'items',
            'payments',
            'orderPromotion',
            'orderVoucher',
            'splits.items.orderItem',
        ]);

        $split = null;
        if ($orderSplitId !== null) {
            $split = $order->splits->firstWhere('id', $orderSplitId)
                ?? OrderSplit::query()
                    ->where('order_id', $order->id)
                    ->whereKey($orderSplitId)
                    ->with(['items.orderItem'])
                    ->first();
            if ($split === null) {
                throw ValidationException::withMessages(['orderSplitId' => ['Split not found for order.']]);
            }
        }

        $ratio = $this->splitRatio($order, $split);
        $lines = $split !== null
            ? $this->buildSplitLines($split)
            : $this->buildFullOrderLines($order);

        $subtotal = $split !== null
            ? round($this->splitSubtotal($split), 2)
            : (float) $order->subtotal;

        $tax = $split !== null
            ? round((float) $order->tax * $ratio, 2)
            : (float) $order->tax;

        $discountLines = $this->buildDiscountLines($order, $outletId, $ratio);
        $discountTotal = round(collect($discountLines)->sum(fn (array $line): float => abs((float) $line['amount'])), 2);

        $total = $split !== null
            ? max(0.0, round($subtotal + $tax - $discountTotal, 2))
            : (float) $order->total;

        $payments = $this->buildPaymentLines($order, $orderSplitId);

        return [
            'order_code' => (string) $order->code,
            'order_channel' => (string) ($order->order_channel ?? ''),
            'order_type' => (string) ($order->order_type ?? ''),
            'service_mode' => (string) ($order->service_mode ?? ''),
            'table' => $order->table_name,
            'customer' => $order->customer_name,
            'customer_display' => $this->thermalReceiptLayout->formatCustomerDisplay($order->customer_name),
            'cashier_name' => $this->resolveCashierName($cashier, $order),
            'split_label' => $split !== null ? (string) $split->label : null,
            'paid_at' => $this->resolvePaidAt($order, $orderSplitId)?->toIso8601String(),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'paid_total' => $split !== null
                ? $this->splitPaidAmount($order, (int) $split->id)
                : (float) $order->paid_total,
            'discount_lines' => $discountLines,
            'payments' => $payments,
            'lines' => $lines,
            'receipt_branding' => $receiptBranding,
        ];
    }

    /**
     * Proforma bill snapshot (unpaid order, no payment rows).
     *
     * @param  array{outletName:string,header:string,footer:string,showTaxBreakdown:bool,showLogo:bool,logoVersion:int,logoUrl:?string}  $receiptBranding
     * @return array<string,mixed>
     */
    public function buildBillFromOrder(
        Order $order,
        int $outletId,
        ?User $cashier,
        array $receiptBranding,
    ): array {
        $snapshot = $this->buildFromOrder($order, $outletId, $cashier, null, $receiptBranding);
        $balanceDue = max(0.0, (float) ($order->balance_due ?? ((float) $order->total - (float) $order->paid_total)));

        $snapshot['payments'] = [];
        $snapshot['paid_total'] = (float) $order->paid_total;
        $snapshot['balance_due'] = $balanceDue;
        $snapshot['is_proforma'] = true;
        $snapshot['document_title'] = 'BILL';
        $snapshot['paid_at'] = null;

        return $snapshot;
    }

    public function splitDueAmount(Order $order, int $splitId): float
    {
        $order->loadMissing(['orderPromotion', 'orderVoucher', 'splits.items']);
        $split = $order->splits->firstWhere('id', $splitId)
            ?? OrderSplit::query()->where('order_id', $order->id)->whereKey($splitId)->with('items')->first();
        if ($split === null) {
            return 0.0;
        }

        $ratio = $this->splitRatio($order, $split);
        $subtotal = round($this->splitSubtotal($split), 2);
        $tax = round((float) $order->tax * $ratio, 2);
        $discountTotal = round(collect($this->buildDiscountLines($order, (int) ($order->outlet_id ?? 0), $ratio))
            ->sum(fn (array $line): float => abs((float) $line['amount'])), 2);

        return max(0.0, round($subtotal + $tax - $discountTotal, 2));
    }

    public function isSplitFullyPaid(Order $order, int $splitId): bool
    {
        $due = $this->splitDueAmount($order, $splitId);
        if ($due <= 0) {
            return false;
        }

        return $this->splitPaidAmount($order, $splitId) + 0.00001 >= $due;
    }

    private function resolveCashierName(?User $cashier, Order $order): string
    {
        $name = trim((string) ($cashier?->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        if ($order->pos_session_id !== null) {
            $session = PosSession::query()
                ->with('openedBy')
                ->find((int) $order->pos_session_id);
            $openedBy = trim((string) ($session?->openedBy?->name ?? ''));
            if ($openedBy !== '') {
                return $openedBy;
            }
        }

        return '';
    }

    private function splitRatio(Order $order, ?OrderSplit $split): float
    {
        if ($split === null) {
            return 1.0;
        }

        $orderSubtotal = (float) $order->subtotal;
        if ($orderSubtotal <= 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, $this->splitSubtotal($split) / $orderSubtotal));
    }

    private function splitSubtotal(OrderSplit $split): float
    {
        return (float) $split->items->sum(fn (OrderSplitItem $item): float => (float) $item->amount);
    }

    /**
     * @return list<array{name:string,qty:float,price:float,notes:?string}>
     */
    private function buildFullOrderLines(Order $order): array
    {
        $lines = [];
        foreach ($order->items as $row) {
            $lines[] = [
                'name' => (string) $row->name,
                'qty' => (float) $row->qty,
                'price' => (float) $row->price,
                'notes' => $row->notes,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{name:string,qty:float,price:float,notes:?string}>
     */
    private function buildSplitLines(OrderSplit $split): array
    {
        $lines = [];
        foreach ($split->items as $splitItem) {
            $qty = (float) $splitItem->qty;
            $amount = (float) $splitItem->amount;
            $unitPrice = $qty > 0 ? round($amount / $qty, 2) : 0.0;
            $name = $splitItem->orderItem !== null ? (string) $splitItem->orderItem->name : 'Line';

            $lines[] = [
                'name' => $name,
                'qty' => $qty,
                'price' => $unitPrice,
                'notes' => $splitItem->orderItem?->notes,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{type:string,label:string,amount:float}>
     */
    private function buildDiscountLines(Order $order, int $outletId, float $ratio): array
    {
        $lines = [];

        if ($order->relationLoaded('orderPromotion') && $order->orderPromotion !== null) {
            $amount = round((float) $order->orderPromotion->discount_amount * $ratio, 2);
            if ($amount > 0) {
                $label = trim((string) ($order->orderPromotion->promotion_code ?: $order->orderPromotion->promotion_name ?: 'Promo'));
                $lines[] = ['type' => 'promotion', 'label' => $label !== '' ? $label : 'Promo', 'amount' => -$amount];
            }
        }

        if ($order->relationLoaded('orderVoucher') && $order->orderVoucher !== null) {
            $amount = round((float) $order->orderVoucher->discount_amount * $ratio, 2);
            if ($amount > 0) {
                $label = trim((string) ($order->orderVoucher->voucher_code ?: 'Voucher'));
                $lines[] = ['type' => 'voucher', 'label' => $label !== '' ? $label : 'Voucher', 'amount' => -$amount];
            }
        }

        $composition = $this->giftCardAccountingService->compositionFromOrderId((int) $order->id, $outletId > 0 ? $outletId : null);
        if ($composition->giftCardAmount > 0) {
            $amount = round($composition->giftCardAmount * $ratio, 2);
            if ($amount > 0) {
                $lines[] = ['type' => 'gift_card', 'label' => 'Gift Card', 'amount' => -$amount];
            }
        }
        if ($composition->storeCreditAmount > 0) {
            $amount = round($composition->storeCreditAmount * $ratio, 2);
            if ($amount > 0) {
                $lines[] = ['type' => 'store_credit', 'label' => 'Store Credit', 'amount' => -$amount];
            }
        }

        return $lines;
    }

    /**
     * @return list<array{method:string,amount:float,label:string}>
     */
    private function buildPaymentLines(Order $order, ?int $orderSplitId): array
    {
        return $order->payments
            ->filter(function (Payment $payment) use ($orderSplitId): bool {
                if ((string) ($payment->status ?? 'paid') !== 'paid') {
                    return false;
                }
                if ($orderSplitId !== null) {
                    return (int) $payment->order_split_id === $orderSplitId;
                }

                return true;
            })
            ->map(fn (Payment $payment): array => [
                'method' => (string) $payment->method,
                'amount' => (float) $payment->amount,
                'label' => $this->formatPaymentMethodLabel((string) $payment->method),
            ])
            ->values()
            ->all();
    }

    public function formatPaymentMethodLabel(string $method): string
    {
        return match (strtolower(trim($method))) {
            'cash' => 'Cash',
            'qris' => 'QRIS',
            'ewallet' => 'E-Wallet',
            'card' => 'Card',
            'transfer' => 'Transfer',
            default => ucwords(str_replace(['_', '-'], ' ', strtolower(trim($method)))),
        };
    }

    private function splitPaidAmount(Order $order, int $splitId): float
    {
        return round((float) Payment::query()
            ->where('order_id', $order->id)
            ->where('order_split_id', $splitId)
            ->where('status', 'paid')
            ->sum('amount'), 2);
    }

    private function resolvePaidAt(Order $order, ?int $orderSplitId): ?Carbon
    {
        $payments = $order->payments->filter(function (Payment $payment) use ($orderSplitId): bool {
            if ($orderSplitId !== null) {
                return (int) $payment->order_split_id === $orderSplitId;
            }

            return true;
        });

        $paidAt = $payments->pluck('paid_at')->filter()->max();

        return $paidAt instanceof Carbon ? $paidAt : null;
    }
}
