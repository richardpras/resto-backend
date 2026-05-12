<?php

namespace Tests\Unit;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderPaymentAllocation;
use App\Models\Modules\Orders\Domain\Payment;
use App\Modules\Orders\Services\RecoverySettlement\RecoveryLoyaltyAdjustmentCalculator;
use App\Modules\Orders\Services\RecoverySettlement\RecoveryRefundAllocationCalculator;
use App\Modules\Orders\Services\RecoverySettlement\RecoveryReplacementDeltaCalculator;
use App\Modules\Orders\Services\RecoverySettlement\RecoverySettlementComposer;
use PHPUnit\Framework\TestCase;

class RecoverySettlementComposerTest extends TestCase
{
    public function test_refund_cap_uses_proportional_paid_when_no_allocations(): void
    {
        $order = new Order([
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 10000,
            'balance_due' => 0,
            'payment_status' => 'paid',
            'source' => 'pos',
        ]);
        $order->setRelation('payments', collect([]));
        $order->setRelation('splits', collect([]));

        $line = new OrderItem(['order_id' => 1, 'qty' => 1, 'price' => 10000, 'line_total' => 10000, 'name' => 'A']);
        $line->forceFill(['id' => 1]);
        $order->setRelation('items', collect([$line]));

        $composer = new RecoverySettlementComposer(
            new RecoveryRefundAllocationCalculator,
            new RecoveryReplacementDeltaCalculator,
            new RecoveryLoyaltyAdjustmentCalculator,
        );

        $out = $composer->compose($order, $line, [
            'settlementKind' => 'partial_refund',
            'partialRefundAmount' => 15000,
            'storeCreditAmount' => 0,
            'giftCardAmount' => 0,
        ]);

        self::assertSame(10000.0, $out['refund']['capped']);
    }

    public function test_replacement_delta_positive_when_replacement_more_expensive(): void
    {
        $old = new OrderItem(['order_id' => 1, 'qty' => 1, 'price' => 5000, 'line_total' => 5000, 'name' => 'Old']);
        $old->forceFill(['id' => 1]);
        $new = new OrderItem(['order_id' => 1, 'qty' => 1, 'price' => 8000, 'line_total' => 8000, 'name' => 'New']);
        $new->forceFill(['id' => 2]);
        $order = new Order([
            'subtotal' => 13000,
            'tax' => 0,
            'total' => 13000,
            'paid_total' => 13000,
            'payment_status' => 'paid',
            'source' => 'pos',
        ]);
        $order->setRelation('items', collect([$old, $new]));
        $order->setRelation('payments', collect([]));
        $order->setRelation('splits', collect([]));

        $composer = new RecoverySettlementComposer(
            new RecoveryRefundAllocationCalculator,
            new RecoveryReplacementDeltaCalculator,
            new RecoveryLoyaltyAdjustmentCalculator,
        );

        $out = $composer->compose($order, $old, [
            'replacedByOrderItemId' => 2,
        ]);

        self::assertSame(3000.0, $out['replacement']['delta']);
    }

    public function test_allocation_on_line_caps_refund(): void
    {
        $line = new OrderItem(['order_id' => 1, 'qty' => 1, 'price' => 4000, 'line_total' => 4000, 'name' => 'L']);
        $line->forceFill(['id' => 10]);
        $payment = new Payment(['id' => 99, 'order_id' => 1, 'amount' => 4000, 'method' => 'cash', 'status' => 'paid']);
        $payment->setRelation('allocations', collect([
            new OrderPaymentAllocation(['payment_id' => 99, 'order_item_id' => 10, 'qty' => 1, 'amount' => 4000]),
        ]));
        $order = new Order([
            'subtotal' => 10000,
            'paid_total' => 10000,
            'payment_status' => 'paid',
            'source' => 'pos',
        ]);
        $order->setRelation('payments', collect([$payment]));
        $order->setRelation('splits', collect([]));
        $order->setRelation('items', collect([$line]));

        $composer = new RecoverySettlementComposer(
            new RecoveryRefundAllocationCalculator,
            new RecoveryReplacementDeltaCalculator,
            new RecoveryLoyaltyAdjustmentCalculator,
        );

        $out = $composer->compose($order, $line, ['partialRefundAmount' => 99999]);

        self::assertSame(4000.0, $out['refund']['capped']);
    }
}
