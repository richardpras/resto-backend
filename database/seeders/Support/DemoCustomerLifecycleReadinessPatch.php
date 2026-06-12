<?php

namespace Database\Seeders\Support;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Database\Seeders\Demo\DemoSeederContext;
use Illuminate\Support\Facades\DB;

/**
 * DEMO-DATA-SEEDER-03 — customer QR lifecycle, order source links, call cashier.
 */
final class DemoCustomerLifecycleReadinessPatch
{
    public static function apply(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            self::seedLifecycleOrders($outlet);
            self::seedAdditionalOrderScenario($outlet);
            self::seedOrderSourceShowcase($outlet);
            self::seedCallCashierNotifications($outlet);
        }
    }

    private static function seedLifecycleOrders(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $table = DemoPatch03Support::tableForOutlet($outlet, $outlet->code === 'DEMO-SUNSET' ? 'A01' : 'B01');
        $esTeh = DemoPatch03Support::menuItemByName($outlet, 'Es Teh Manis');
        $esJeruk = DemoPatch03Support::menuItemByName($outlet, 'Es Jeruk');
        $nasi = DemoPatch03Support::menuItemByName($outlet, 'Nasi Goreng Nusantara');
        $cashier = DemoPatch03Support::cashierForOutlet($outlet);
        $base = DemoPatch03Support::baseTime();

        $scenarios = [
            'PENDING' => ['internal' => 'pending_cashier_confirmation', 'kitchen' => null, 'payment' => null, 'lines' => [['menuItem' => $esTeh, 'qty' => 2]], 'events' => ['customer_order.created']],
            'UNDER-REVIEW' => ['internal' => 'under_review', 'kitchen' => null, 'payment' => null, 'lines' => [['menuItem' => $nasi, 'qty' => 1]], 'events' => ['customer_order.created', 'customer_order.reviewed'], 'reviewed' => true],
            'ADJUSTED' => ['internal' => 'under_review', 'kitchen' => null, 'payment' => null, 'adjusted' => true, 'lines' => [['menuItem' => $esJeruk, 'qty' => 2]], 'original' => [['menuItem' => $esTeh, 'qty' => 3]], 'events' => ['customer_order.created', 'customer_order.reviewed', 'customer_order.adjusted'], 'reviewed' => true],
            'CONFIRMED' => ['internal' => 'confirmed', 'kitchen' => 'queued', 'payment' => 'unpaid', 'lines' => [['menuItem' => $nasi, 'qty' => 1], ['menuItem' => $esTeh, 'qty' => 1]], 'events' => ['customer_order.created', 'customer_order.reviewed', 'customer_order.confirmed'], 'confirmed' => true],
            'COOKING' => ['internal' => 'confirmed', 'kitchen' => 'cooking', 'payment' => 'unpaid', 'lines' => [['menuItem' => $nasi, 'qty' => 1]], 'events' => ['customer_order.created', 'customer_order.confirmed', 'customer_order.sent_to_kitchen'], 'confirmed' => true],
            'READY' => ['internal' => 'confirmed', 'kitchen' => 'ready', 'payment' => 'unpaid', 'lines' => [['menuItem' => $nasi, 'qty' => 1]], 'events' => ['customer_order.created', 'customer_order.confirmed', 'customer_order.sent_to_kitchen', 'customer_order.ready'], 'confirmed' => true],
            'SERVED' => ['internal' => 'confirmed', 'kitchen' => 'served', 'payment' => 'unpaid', 'lines' => [['menuItem' => $nasi, 'qty' => 1]], 'events' => ['customer_order.created', 'customer_order.confirmed', 'customer_order.served'], 'confirmed' => true, 'served' => true],
            'COMPLETED' => ['internal' => 'paid', 'kitchen' => 'completed', 'payment' => 'paid', 'lines' => [['menuItem' => $nasi, 'qty' => 1], ['menuItem' => $esTeh, 'qty' => 1]], 'events' => ['customer_order.created', 'customer_order.confirmed', 'customer_order.completed'], 'confirmed' => true, 'orderStatus' => 'completed'],
            'CANCELLED' => ['internal' => 'rejected', 'kitchen' => null, 'payment' => null, 'lines' => [['menuItem' => $esTeh, 'qty' => 1]], 'events' => ['customer_order.created'], 'rejected' => true],
        ];

        foreach ($scenarios as $suffix => $spec) {
            $requestCode = "{$prefix}-QRO-{$suffix}";
            $posCode = "{$prefix}-POS-{$suffix}";

            $reviewDraft = null;
            if (! empty($spec['adjusted'])) {
                $reviewDraft = [
                    'items' => collect($spec['lines'])->map(fn (array $line): array => [
                        'menuItemId' => (int) $line['menuItem']->id,
                        'name' => (string) $line['menuItem']->name,
                        'qty' => (float) $line['qty'],
                        'unitPrice' => (float) $line['menuItem']->price,
                    ])->values()->all(),
                    'adjustments' => [[
                        'type' => 'changed',
                        'name' => 'Es Teh Manis',
                        'reason' => 'Sold Out',
                        'original' => ['qty' => 3],
                        'updated' => ['qty' => 2],
                        'from' => '3x Es Teh',
                        'to' => '2x Es Jeruk',
                    ]],
                    'subtotal' => collect($spec['lines'])->sum(fn (array $line): float => (float) $line['qty'] * (float) $line['menuItem']->price),
                    'discount' => 0,
                    'total' => collect($spec['lines'])->sum(fn (array $line): float => (float) $line['qty'] * (float) $line['menuItem']->price),
                ];
            }

            $request = QrOrderRequest::query()->updateOrCreate(
                ['request_code' => $requestCode],
                [
                    'outlet_id' => $outlet->id,
                    'table_id' => $table->id,
                    'customer_name' => "Demo Guest {$suffix}",
                    'status' => (string) $spec['internal'],
                    'expires_at' => now()->addHours(2),
                    'reviewed_at' => ! empty($spec['reviewed']) ? $base->addMinutes(15) : null,
                    'reviewed_by_user_id' => ! empty($spec['reviewed']) ? $cashier?->id : null,
                    'confirmed_at' => ! empty($spec['confirmed']) ? $base->addMinutes(25) : null,
                    'confirmed_by_user_id' => ! empty($spec['confirmed']) ? $cashier?->id : null,
                    'rejected_at' => ! empty($spec['rejected']) ? $base->addMinutes(20) : null,
                    'rejection_reason' => ! empty($spec['rejected']) ? 'Customer cancelled at counter' : null,
                    'review_draft' => $reviewDraft,
                    'adjustment_log' => ! empty($spec['adjusted']) ? [['at' => $base->addMinutes(18)->toIso8601String(), 'byUserId' => $cashier?->id, 'summary' => $reviewDraft['adjustments'] ?? []]] : null,
                    'customer_served_at' => ! empty($spec['served']) ? $base->addMinutes(45) : null,
                    'cashier_call_count' => $suffix === 'PENDING' ? 1 : 0,
                    'cashier_called_at' => $suffix === 'PENDING' ? $base->addMinutes(10) : null,
                    'last_cashier_call_reason' => $suffix === 'PENDING' ? 'need_assistance' : null,
                ],
            );

            DemoPatch03Support::syncQrItems($request, $spec['lines']);

            if ($spec['kitchen'] !== null && $spec['payment'] !== null) {
                DemoPatch03Support::upsertLinkedPosOrder(
                    $outlet,
                    $posCode,
                    $request,
                    $spec['lines'],
                    (string) $spec['payment'],
                    (string) $spec['kitchen'],
                    (string) ($spec['orderStatus'] ?? 'confirmed'),
                );

                $order = Order::query()->where('code', $posCode)->first();
                if ($order !== null) {
                    KitchenTicket::query()->updateOrCreate(
                        ['order_id' => $order->id],
                        [
                            'outlet_id' => $outlet->id,
                            'ticket_no' => "{$posCode}-KT",
                            'status' => match ((string) $spec['kitchen']) {
                                'completed' => 'completed',
                                'served' => 'served',
                                'ready' => 'ready',
                                'cooking' => 'in_progress',
                                default => 'queued',
                            },
                            'queued_at' => $base->addMinutes(30),
                            'started_at' => in_array($spec['kitchen'], ['cooking', 'ready', 'served', 'completed'], true) ? $base->addMinutes(32) : null,
                            'ready_at' => in_array($spec['kitchen'], ['ready', 'served', 'completed'], true) ? $base->addMinutes(40) : null,
                            'served_at' => in_array($spec['kitchen'], ['served', 'completed'], true) ? $base->addMinutes(45) : null,
                        ],
                    );
                }
            }

            foreach ($spec['events'] as $eventType) {
                DemoPatch03Support::audit($outlet, $request, $eventType, $cashier);
            }
        }
    }

    private static function seedAdditionalOrderScenario(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $table = DemoPatch03Support::tableForOutlet($outlet, $outlet->code === 'DEMO-SUNSET' ? 'A02' : 'B02');
        $esTeh = DemoPatch03Support::menuItemByName($outlet, 'Es Teh Manis');
        $nasi = DemoPatch03Support::menuItemByName($outlet, 'Nasi Goreng Nusantara');
        $cashier = DemoPatch03Support::cashierForOutlet($outlet);
        $requestCode = "{$prefix}-QRO-ADDITIONAL";
        $posCode = "{$prefix}-POS-ADDITIONAL";

        $request = QrOrderRequest::query()->updateOrCreate(
            ['request_code' => $requestCode],
            [
                'outlet_id' => $outlet->id,
                'table_id' => $table->id,
                'customer_name' => 'Demo Additional Guest',
                'status' => 'confirmed',
                'expires_at' => now()->addHours(3),
                'confirmed_at' => DemoPatch03Support::baseTime()->addMinutes(30),
                'confirmed_by_user_id' => $cashier?->id,
            ],
        );

        DemoPatch03Support::syncQrItems($request, [
            ['menuItem' => $esTeh, 'qty' => 2, 'notes' => 'Original round'],
            ['menuItem' => $nasi, 'qty' => 1, 'notes' => 'Additional round'],
        ]);

        DemoPatch03Support::upsertLinkedPosOrder(
            $outlet,
            $posCode,
            $request,
            [
                ['menuItem' => $esTeh, 'qty' => 2, 'notes' => 'Original round'],
                ['menuItem' => $nasi, 'qty' => 1, 'notes' => 'Additional round'],
            ],
            'unpaid',
            'cooking',
        );

        DemoPatch03Support::audit($outlet, $request, 'customer_order.created', $cashier, ['appended' => true]);
        DemoPatch03Support::audit($outlet, $request, 'customer_order.confirmed', $cashier);
    }

    private static function seedOrderSourceShowcase(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $table = DemoPatch03Support::tableForOutlet($outlet, $outlet->code === 'DEMO-SUNSET' ? 'A03' : 'B03');
        $nasi = DemoPatch03Support::menuItemByName($outlet, 'Nasi Goreng Nusantara');
        $session = DemoPatch03Support::openPosSession($outlet);
        $subtotal = (float) $nasi->price;
        $tax = round($subtotal * 0.11, 2);

        Order::query()->updateOrCreate(
            ['code' => "{$prefix}-POS-DIRECT-OPEN"],
            [
                'outlet_id' => $outlet->id,
                'pos_session_id' => $session->id,
                'source' => 'pos',
                'source_type' => 'direct_pos',
                'source_id' => null,
                'source_code' => null,
                'order_channel' => 'pos',
                'service_mode' => 'dine_in',
                'order_type' => 'Dine In',
                'status' => 'confirmed',
                'payment_status' => 'unpaid',
                'kitchen_status' => 'queued',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $subtotal + $tax,
                'table_id' => $table->id,
                'table_name' => $table->name,
            ],
        );
    }

    private static function seedCallCashierNotifications(Outlet $outlet): void
    {
        $manager = User::query()
            ->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outlet->id))
            ->where('email', 'like', '%manager%')
            ->first();
        if ($manager === null) {
            $manager = DemoPatch03Support::cashierForOutlet($outlet);
        }
        if ($manager === null) {
            return;
        }

        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $pairs = [
            ['customer_call_cashier', 'Need Assistance', 'Customer needs assistance at table'],
            ['customer_request_bill', 'Request Bill', 'Customer requests bill at table'],
            ['customer_call_cashier', 'Order Question', 'Customer has an order question'],
            ['customer_call_cashier', 'Other', 'Customer called cashier (other)'],
        ];

        foreach ($pairs as $index => [$sourceType, $title, $message]) {
            UserNotification::query()->updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'user_id' => (int) $manager->id,
                    'source_module' => 'orders',
                    'source_type' => $sourceType,
                    'source_id' => "{$prefix}-call-{$index}",
                ],
                [
                    'severity' => UserNotification::SEVERITY_INFO,
                    'title' => $title,
                    'message' => "{$message} — {$prefix}",
                    'action_url' => '/qr-orders',
                    'metadata' => ['demoPatch' => '03', 'reason' => strtolower(str_replace(' ', '_', $title))],
                ],
            );
        }
    }
}
