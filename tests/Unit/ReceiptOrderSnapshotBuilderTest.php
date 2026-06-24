<?php

namespace Tests\Unit;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderPromotion;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\OrderSplitItem;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Print\Services\ReceiptOrderSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptOrderSnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptOrderSnapshotBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(ReceiptOrderSnapshotBuilder::class);
    }

    public function test_full_order_snapshot_includes_cashier_and_full_discounts(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'snap-'.uniqid(),
            'name' => 'Snapshot Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $cashier = User::factory()->create(['name' => 'Kasir Utama']);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'ORD-SNAP-1',
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 9500,
            'paid_total' => 9500,
            'balance_due' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_id' => 1,
            'name' => 'Nasi Goreng',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
        ]);

        OrderPromotion::query()->create([
            'order_id' => $order->id,
            'promotion_code' => 'SAVE10',
            'promotion_name' => 'Save Ten',
            'discount_type' => 'fixed',
            'discount_value' => 1500,
            'discount_amount' => 1500,
            'applied_at' => now(),
        ]);

        Payment::query()->create([
            'order_id' => $order->id,
            'method' => 'cash',
            'amount' => 9500,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $snapshot = $this->builder->buildFromOrder(
            $order->fresh(['items', 'payments', 'orderPromotion']),
            (int) $outlet->id,
            $cashier,
            null,
            $this->branding(),
        );

        $this->assertSame('Kasir Utama', $snapshot['cashier_name']);
        $this->assertNull($snapshot['split_label']);
        $this->assertCount(1, $snapshot['lines']);
        $this->assertSame('Nasi Goreng', $snapshot['lines'][0]['name']);
        $this->assertCount(1, $snapshot['discount_lines']);
        $this->assertSame('promotion', $snapshot['discount_lines'][0]['type']);
        $this->assertSame('SAVE10', $snapshot['discount_lines'][0]['label']);
        $this->assertSame(-1500.0, $snapshot['discount_lines'][0]['amount']);
        $this->assertCount(1, $snapshot['payments']);
        $this->assertSame('Cash', $snapshot['payments'][0]['label']);
    }

    public function test_split_snapshot_filters_items_and_applies_proportional_discount(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'snap-split-'.uniqid(),
            'name' => 'Split Snapshot Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $cashier = User::factory()->create(['name' => 'Kasir Split']);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'ORD-SNAP-SPLIT',
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'partial',
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 9500,
            'paid_total' => 2750,
            'balance_due' => 6750,
        ]);

        $itemA = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_id' => 1,
            'name' => 'Nasi Goreng',
            'qty' => 1,
            'price' => 5000,
            'line_total' => 5000,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_id' => 2,
            'name' => 'Mie Goreng',
            'qty' => 1,
            'price' => 5000,
            'line_total' => 5000,
        ]);

        OrderPromotion::query()->create([
            'order_id' => $order->id,
            'promotion_code' => 'SAVE10',
            'promotion_name' => 'Save Ten',
            'discount_type' => 'fixed',
            'discount_value' => 1500,
            'discount_amount' => 1500,
            'applied_at' => now(),
        ]);

        $split = OrderSplit::query()->create([
            'order_id' => $order->id,
            'split_type' => 'by_item',
            'label' => 'Guest A',
            'status' => 'open',
        ]);
        OrderSplitItem::query()->create([
            'order_split_id' => $split->id,
            'order_item_id' => $itemA->id,
            'qty' => 1,
            'amount' => 5000,
        ]);

        Payment::query()->create([
            'order_id' => $order->id,
            'order_split_id' => $split->id,
            'method' => 'cash',
            'amount' => 4750,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $snapshot = $this->builder->buildFromOrder(
            $order->fresh(['items', 'payments', 'orderPromotion', 'splits.items.orderItem']),
            (int) $outlet->id,
            $cashier,
            (int) $split->id,
            $this->branding(),
        );

        $this->assertSame('Guest A', $snapshot['split_label']);
        $this->assertCount(1, $snapshot['lines']);
        $this->assertSame('Nasi Goreng', $snapshot['lines'][0]['name']);
        $this->assertStringNotContainsString('Mie Goreng', json_encode($snapshot['lines']));
        $this->assertSame(5000.0, $snapshot['subtotal']);
        $this->assertSame(500.0, $snapshot['tax']);
        $this->assertSame(-750.0, $snapshot['discount_lines'][0]['amount']);
        $this->assertSame(4750.0, $snapshot['total']);
        $this->assertTrue($this->builder->isSplitFullyPaid($order->fresh(['orderPromotion', 'splits.items']), (int) $split->id));
    }

    public function test_split_due_is_zero_when_order_subtotal_is_zero(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'snap-zero-'.uniqid(),
            'name' => 'Zero Subtotal Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'ORD-ZERO',
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'paid_total' => 0,
            'balance_due' => 0,
        ]);

        $split = OrderSplit::query()->create([
            'order_id' => $order->id,
            'split_type' => 'even',
            'label' => 'Guest A',
            'status' => 'open',
        ]);

        $this->assertSame(0.0, $this->builder->splitDueAmount($order->fresh(['splits.items']), (int) $split->id));
        $this->assertFalse($this->builder->isSplitFullyPaid($order->fresh(['splits.items']), (int) $split->id));
    }

    /** @return array{outletName:string,header:string,footer:string,showTaxBreakdown:bool,showLogo:bool,logoVersion:int,logoUrl:?string} */
    private function branding(): array
    {
        return [
            'outletName' => 'Test Outlet',
            'header' => '',
            'footer' => '',
            'showTaxBreakdown' => true,
            'showLogo' => false,
            'logoVersion' => 0,
            'logoUrl' => null,
        ];
    }
}
