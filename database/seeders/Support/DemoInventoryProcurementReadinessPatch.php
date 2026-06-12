<?php

namespace Database\Seeders\Support;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseInvoicePayment;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Supplier;
use Database\Seeders\Demo\DemoSeederContext;
use Illuminate\Support\Facades\DB;

/**
 * DEMO-DATA-SEEDER-03 — inventory consumption queue, AP invoices, preflight blockers.
 */
final class DemoInventoryProcurementReadinessPatch
{
    public static function apply(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            self::seedConsumptionQueue($outlet);
            self::seedInventoryIncidents($outlet);
            self::seedPurchaseInvoices($outlet);
            self::seedPreflightBlockers($outlet);
        }
    }

    private static function seedConsumptionQueue(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $orders = Order::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_status', 'paid')
            ->orderByDesc('id')
            ->limit(4)
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        $states = [
            'PENDING' => InventoryConsumptionQueue::STATUS_PENDING,
            'PROCESSED' => InventoryConsumptionQueue::STATUS_PROCESSED,
            'REVIEW-REQUIRED' => InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED,
            'FAILED' => InventoryConsumptionQueue::STATUS_FAILED,
        ];

        $index = 0;
        foreach ($states as $suffix => $status) {
            $demoKey = "{$prefix}-CONSUMPTION-{$suffix}";
            $order = $orders[$index] ?? $orders->last();
            $index++;

            $existing = InventoryConsumptionQueue::query()
                ->where('outlet_id', $outlet->id)
                ->where('payload->demoKey', $demoKey)
                ->first();

            $attrs = [
                'order_id' => $order->id,
                'status' => $status,
                'payload' => ['demoKey' => $demoKey, 'demoPatch' => '03'],
                'failure_reason' => $status === InventoryConsumptionQueue::STATUS_FAILED ? 'Ingredient shortage during posting' : null,
                'processed_at' => $status === InventoryConsumptionQueue::STATUS_PROCESSED ? now()->subHour() : null,
            ];

            if ($existing !== null) {
                $existing->update($attrs);
            } else {
                InventoryConsumptionQueue::query()->create(array_merge(['outlet_id' => $outlet->id], $attrs));
            }
        }
    }

    private static function seedInventoryIncidents(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $events = [
            'inventory_shortage' => 'Inventory shortage detected during consumption',
            'inventory_variance' => 'Stock variance detected after shift close',
            'negative_stock_detected' => 'Negative stock detected on Chicken Breast',
            'inventory_posting_failed' => 'Inventory consumption posting failed',
        ];

        foreach ($events as $eventType => $label) {
            PosEventLog::query()->updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'entity_type' => 'inventory',
                    'entity_id' => abs(crc32("{$prefix}-{$eventType}")),
                    'event_type' => $eventType,
                ],
                [
                    'payload' => ['demoReference' => "{$prefix}-{$eventType}", 'label' => $label, 'demoPatch' => '03'],
                    'occurred_at' => now()->subHours(2),
                ],
            );
        }
    }

    private static function seedPurchaseInvoices(Outlet $outlet): void
    {
        if (! DB::getSchemaBuilder()->hasTable('purchase_invoices')) {
            return;
        }

        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $supplier = Supplier::query()->first();
        if ($supplier === null) {
            $supplier = Supplier::query()->create([
                'name' => 'Demo Supplier PT',
                'contact' => '021000000',
                'email' => 'supplier@demo.local',
                'address' => 'Jl. Supplier Demo 1',
                'notes' => 'Demo procurement supplier',
                'status' => 'active',
            ]);
        }

        $poId = DB::table('purchase_orders')->where('number', 'DEMO-PO-0002')->value('id');
        $grnId = DB::table('goods_receiving_notes')->where('number', 'DEMO-GRN-0002')->value('id');

        $invoices = [
            ['suffix' => 'OUTSTANDING', 'status' => 'approved', 'paid' => 0, 'total' => 2400000],
            ['suffix' => 'PARTIAL', 'status' => 'approved', 'paid' => 1200000, 'total' => 2400000],
            ['suffix' => 'PAID', 'status' => 'paid', 'paid' => 1800000, 'total' => 1800000],
            ['suffix' => 'OVERDUE', 'status' => 'approved', 'paid' => 0, 'total' => 950000, 'overdue' => true],
        ];

        foreach ($invoices as $row) {
            $number = "{$prefix}-PI-{$row['suffix']}";
            $outstanding = max(0, $row['total'] - $row['paid']);
            $invoice = PurchaseInvoice::query()->updateOrCreate(
                ['number' => $number],
                [
                    'tenant_id' => 1,
                    'outlet_id' => $outlet->id,
                    'purchase_order_id' => $poId,
                    'goods_receiving_note_id' => $grnId,
                    'supplier_id' => $supplier->id,
                    'supplier_invoice_no' => "SUP-{$number}",
                    'invoice_date' => now()->subDays(10)->toDateString(),
                    'due_date' => ! empty($row['overdue']) ? now()->subDays(5)->toDateString() : now()->addDays(14)->toDateString(),
                    'subtotal' => $row['total'] * 0.9,
                    'tax_amount' => $row['total'] * 0.1,
                    'tax_percentage' => 10,
                    'discount_amount' => 0,
                    'total_amount' => $row['total'],
                    'total' => $row['total'],
                    'tax' => $row['total'] * 0.1,
                    'paid_amount' => $row['paid'],
                    'outstanding_amount' => $outstanding,
                    'status' => $row['status'],
                    'approved_at' => now()->subDays(8),
                ],
            );

            if ($row['paid'] > 0) {
                PurchaseInvoicePayment::query()->updateOrCreate(
                    ['purchase_invoice_id' => $invoice->id, 'reference_no' => "{$number}-PAY-1"],
                    [
                        'amount' => $row['paid'],
                        'payment_date' => now()->subDays(3)->toDateString(),
                        'payment_method' => 'bank_transfer',
                        'notes' => 'Demo patch 03 payment',
                    ],
                );
            }
        }
    }

    private static function seedPreflightBlockers(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);

        AccountingPostingFailure::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'source_type' => 'shift_close_demo_03',
                'source_id' => abs(crc32($prefix)),
            ],
            [
                'error_code' => AccountingPostingFailure::ERROR_POSTING,
                'status' => AccountingPostingFailure::STATUS_PENDING,
                'error_message' => 'Demo shift-close posting failure for preflight',
                'payload_json' => ['demoPatch' => '03', 'demoReference' => "{$prefix}-PREFLIGHT-FAIL"],
            ],
        );

        $order = Order::query()
            ->where('outlet_id', $outlet->id)
            ->orderByDesc('id')
            ->first();

        PrintJob::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'idempotency_key' => "{$prefix}-PRINT-RETRY",
            ],
            [
                'type' => 'receipt',
                'source_type' => 'order',
                'source_id' => $order?->id ?? abs(crc32($prefix)),
                'status' => 'failed',
                'content' => ['demoPatch' => '03', 'retry' => true],
                'last_error' => 'Printer unreachable — demo retry job',
                'attempts' => 2,
                'retryable' => true,
                'queued_at' => now()->subMinutes(20),
                'failed_at' => now()->subMinutes(10),
            ],
        );
    }
}
