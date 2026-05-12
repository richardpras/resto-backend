<?php

namespace App\Modules\Orders\Services\RecoverySettlement;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use Illuminate\Validation\ValidationException;

/**
 * Composes read-only recovery settlement financial preview (no payments / loyalty mutations).
 */
final class RecoverySettlementComposer
{
    public function __construct(
        private readonly RecoveryRefundAllocationCalculator $refundCalculator,
        private readonly RecoveryReplacementDeltaCalculator $replacementCalculator,
        private readonly RecoveryLoyaltyAdjustmentCalculator $loyaltyCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compose(Order $order, OrderItem $line, array $input): array
    {
        $settlementKind = strtolower(trim((string) ($input['settlementKind'] ?? 'composite')));
        $partialRefund = isset($input['partialRefundAmount']) ? (float) $input['partialRefundAmount'] : 0.0;
        $storeCredit = isset($input['storeCreditAmount']) ? (float) $input['storeCreditAmount'] : 0.0;
        $giftCard = isset($input['giftCardAmount']) ? (float) $input['giftCardAmount'] : 0.0;
        $replacedBy = isset($input['replacedByOrderItemId']) ? (int) $input['replacedByOrderItemId'] : null;
        $loyaltyManual = array_key_exists('loyaltyPointsAdjustment', $input) && $input['loyaltyPointsAdjustment'] !== null
            ? (int) $input['loyaltyPointsAdjustment']
            : null;

        if ($partialRefund < 0 || $storeCredit < 0 || $giftCard < 0) {
            throw ValidationException::withMessages([
                'amounts' => ['Settlement amounts cannot be negative.'],
            ]);
        }

        $warnings = [];
        $source = strtolower((string) $order->source);
        if ($source === 'qr') {
            $warnings[] = 'QR / prepaid orders: coordinate gateway or prepaid rules before executing refunds outside POS.';
        }

        $refund = $this->refundCalculator->suggest($order, $line, $partialRefund);
        $warnings = array_merge($warnings, $refund['warnings']);

        $replacement = $this->replacementCalculator->delta($order, $line, $replacedBy > 0 ? $replacedBy : null);
        $warnings = array_merge($warnings, $replacement['warnings']);

        $loyalty = $this->loyaltyCalculator->suggest($order, $line, $loyaltyManual);
        $warnings = array_merge($warnings, $loyalty['warnings']);

        $compensationTotal = round($storeCredit + $giftCard, 2);
        $auditNote = sprintf(
            'Recovery settlement preview (%s): refund_capped=%.2f, store_credit=%.2f, gift_card=%.2f, replacement_delta=%.2f, loyalty_rollback=%d',
            $settlementKind,
            $refund['capped'],
            $storeCredit,
            $giftCard,
            (float) $replacement['delta'],
            (int) $loyalty['rollbackPointsSuggested']
        );

        return [
            'settlementKind' => $settlementKind,
            'order' => [
                'id' => (int) $order->id,
                'paymentStatus' => (string) $order->payment_status,
                'source' => (string) $order->source,
                'subtotal' => (float) $order->subtotal,
                'tax' => (float) $order->tax,
                'total' => (float) $order->total,
                'paidTotal' => (float) $order->paid_total,
                'balanceDue' => (float) $order->balance_due,
            ],
            'line' => [
                'orderItemId' => (int) $line->id,
                'name' => (string) $line->name,
                'qty' => (float) $line->qty,
                'price' => (float) $line->price,
                'lineGross' => $this->lineGross($line),
            ],
            'refund' => $refund,
            'compensation' => [
                'storeCreditAmount' => round($storeCredit, 2),
                'giftCardAmount' => round($giftCard, 2),
                'compensationTotal' => $compensationTotal,
            ],
            'replacement' => $replacement,
            'loyalty' => $loyalty,
            'paymentSafety' => [
                'warnings' => array_values(array_unique($warnings)),
                'duplicateRefundGuard' => 'Use idempotencyKey when recording settlement audit rows.',
            ],
            'audit' => [
                'suggestedPaymentAdjustmentNote' => $auditNote,
            ],
        ];
    }

    private function lineGross(OrderItem $line): float
    {
        $lt = $line->line_total;
        if ($lt !== null && (float) $lt > 0) {
            return round((float) $lt, 2);
        }

        return round((float) $line->price * (float) $line->qty, 2);
    }
}
