<?php

namespace Database\Seeders\Support;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Models\User;
use App\Modules\Kitchen\Services\KitchenTicketService;
use App\Modules\Print\Services\PrinterRoutingService;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoSeederContext;
use Illuminate\Support\Facades\DB;

/**
 * DEMO-DATA-SEEDER-02 — idempotent patches on top of DEMO-DATA-SEEDER-01.
 */
final class DemoProductionReadinessPatch
{
    public static function apply(): void
    {
        self::patchDemoOwnerIdentity();
        self::seedCustomerAppUrl();
        self::seedInventoryConsumptionPolicy();
        self::seedOutletPaymentMethodConfigs();
        self::assignProductionStationsToActiveMenuItems();
        self::seedStationRoutingShowcase();
        self::seedKdsStatusShowcase();
        self::seedSoundAlertOrders();
    }

    private static function patchDemoOwnerIdentity(): void
    {
        User::query()->where('email', 'owner@demo.resto.local')->update(['email' => 'owner@restohub.demo']);

        User::query()->updateOrCreate(
            ['email' => 'owner@restohub.demo'],
            ['name' => 'Demo Owner', 'password' => 'demo123', 'pin_hash' => '1234'],
        );
    }

    private static function seedCustomerAppUrl(): void
    {
        $url = trim((string) env('DEMO_CUSTOMER_APP_URL', 'http://localhost:8080'));
        if ($url === '') {
            $url = 'http://localhost:8080';
        }

        $row = SystemSetting::query()->first();
        if ($row !== null) {
            $row->customer_app_url = rtrim($url, '/');
            $row->save();
        }
    }

    private static function seedInventoryConsumptionPolicy(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'stock_enforcement_mode' => 'deferred',
                'enforce_stock_on_sale' => false,
                'allow_negative_stock' => true,
            ],
        );
    }

    private static function seedOutletPaymentMethodConfigs(): void
    {
        $service = app(OutletPaymentMethodConfigService::class);

        foreach (DemoSeederContext::outlets() as $outlet) {
            $service->ensureDefaultsForOutlet((int) $outlet->id);

            $enabledCodes = [
                'cash' => ['enabled' => true, 'is_default' => true],
                'manual_qris' => ['enabled' => true, 'is_default' => false],
                'gateway_qris' => ['enabled' => true, 'is_default' => false, 'provider' => 'midtrans'],
                'manual_transfer' => ['enabled' => false, 'is_default' => false],
            ];

            foreach ($enabledCodes as $code => $attrs) {
                OutletPaymentMethodConfig::query()
                    ->where('outlet_id', $outlet->id)
                    ->where('payment_method_code', $code)
                    ->update($attrs);
            }
        }
    }

    private static function assignProductionStationsToActiveMenuItems(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            $stationIdsByCode = ProductionStation::query()
                ->where('outlet_id', $outlet->id)
                ->pluck('id', 'code');

            MenuItem::query()
                ->where('outlet_id', $outlet->id)
                ->where('available', true)
                ->whereNull('production_station_id')
                ->each(function (MenuItem $item) use ($stationIdsByCode): void {
                    $stationCode = self::inferStationCodeForMenuItem($item);
                    $stationId = $stationIdsByCode->get($stationCode)
                        ?? $stationIdsByCode->get('kitchen');

                    if ($stationId !== null) {
                        $item->production_station_id = (int) $stationId;
                        $item->save();
                    }
                });
        }
    }

    private static function inferStationCodeForMenuItem(MenuItem $item): string
    {
        $category = strtolower((string) $item->category);

        return match (true) {
            in_array($category, ['retail'], true) => 'cashier',
            in_array($category, ['beverage', 'bar'], true) => 'bar',
            in_array($category, ['dessert'], true) => 'dessert',
            default => 'kitchen',
        };
    }

    private static function seedStationRoutingShowcase(): void
    {
        $kitchenTicketService = app(KitchenTicketService::class);
        $printerRouting = app(PrinterRoutingService::class);

        foreach (DemoSeederContext::outlets() as $outlet) {
            /** @var Outlet $outlet */
            $orderCode = "{$outlet->code}-STATION-SHOWCASE";
            $menuNames = ['Nasi Goreng Nusantara', 'Es Teh Manis', 'Croissant', 'Rokok Marlboro'];
            $menuItems = MenuItem::query()
                ->where('outlet_id', $outlet->id)
                ->whereIn('name', $menuNames)
                ->get()
                ->keyBy('name');

            if ($menuItems->count() < 4) {
                continue;
            }

            $subtotal = $menuItems->sum(fn (MenuItem $item): float => (float) $item->price);
            $tax = round($subtotal * 0.11, 2);
            $total = $subtotal + $tax;
            $orderedAt = DemoDatasetSeederService::baseTime()->addDays(20);

            $order = Order::query()->updateOrCreate(
                ['code' => $orderCode],
                [
                    'tenant_id' => null,
                    'outlet_id' => $outlet->id,
                    'source' => 'pos',
                    'order_channel' => 'pos',
                    'service_mode' => 'dine_in',
                    'order_type' => 'dine_in',
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'kitchen_status' => 'queued',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'paid_total' => $total,
                    'balance_due' => 0,
                    'customer_name' => 'Station Routing Showcase',
                    'confirmed_at' => $orderedAt,
                    'is_posted' => true,
                ],
            );

            $lineIndex = 0;
            foreach ($menuNames as $name) {
                $menuItem = $menuItems->get($name);
                if ($menuItem === null) {
                    continue;
                }
                $suffix = chr(65 + $lineIndex);
                OrderItem::query()->updateOrCreate(
                    ['order_id' => $order->id, 'item_id' => "{$menuItem->id}-{$suffix}"],
                    [
                        'name' => $menuItem->name,
                        'emoji' => $menuItem->emoji,
                        'qty' => 1,
                        'price' => $menuItem->price,
                        'line_total' => $menuItem->price,
                        'notes' => null,
                    ],
                );
                $lineIndex++;
            }

            KitchenTicket::query()
                ->where('order_id', $order->id)
                ->whereNull('production_station_id')
                ->delete();

            PrintJob::query()
                ->where('outlet_id', $outlet->id)
                ->where('source_type', 'order')
                ->where('source_id', $order->id)
                ->delete();

            $freshOrder = $order->fresh(['items']);
            if ($freshOrder === null) {
                continue;
            }

            $kitchenTicketService->syncTicketItemsFromOrder($freshOrder);

            $freshOrder = $freshOrder->fresh(['items']);
            if ($freshOrder !== null) {
                $printerRouting->queueKitchenTicketsForOrder($freshOrder);
                $printerRouting->queueReceiptForOrder($freshOrder, 'demo-station-showcase');
            }
        }
    }

    private static function seedKdsStatusShowcase(): void
    {
        $statusMap = [
            'queued' => 'queued',
            'preparing' => 'preparing',
            'ready' => 'ready',
            'served' => 'served',
        ];

        foreach (DemoSeederContext::outlets() as $outlet) {
            $kitchenStation = ProductionStation::query()
                ->where('outlet_id', $outlet->id)
                ->where('code', 'kitchen')
                ->first();

            if ($kitchenStation === null) {
                continue;
            }

            $nasi = MenuItem::query()
                ->where('outlet_id', $outlet->id)
                ->where('name', 'Nasi Goreng Nusantara')
                ->first();

            if ($nasi === null) {
                continue;
            }

            $baseTime = DemoDatasetSeederService::baseTime()->addDays(21);
            $index = 0;

            foreach ($statusMap as $suffix => $status) {
                $orderCode = "{$outlet->code}-KDS-STATUS-".strtoupper($suffix);
                $price = (float) $nasi->price;
                $tax = round($price * 0.11, 2);
                $total = $price + $tax;
                $orderedAt = $baseTime->addMinutes($index * 15);

                $order = Order::query()->updateOrCreate(
                    ['code' => $orderCode],
                    [
                        'tenant_id' => null,
                        'outlet_id' => $outlet->id,
                        'source' => 'pos',
                        'order_channel' => 'pos',
                        'service_mode' => 'takeaway',
                        'order_type' => 'takeaway',
                        'status' => 'confirmed',
                        'payment_status' => 'unpaid',
                        'kitchen_status' => $status,
                        'subtotal' => $price,
                        'tax' => $tax,
                        'total' => $total,
                        'paid_total' => 0,
                        'balance_due' => $total,
                        'customer_name' => "KDS {$status} demo",
                        'confirmed_at' => $orderedAt,
                    ],
                );

                OrderItem::query()->updateOrCreate(
                    ['order_id' => $order->id, 'item_id' => "{$nasi->id}-KDS"],
                    [
                        'name' => $nasi->name,
                        'emoji' => $nasi->emoji,
                        'qty' => 1,
                        'price' => $nasi->price,
                        'line_total' => $nasi->price,
                        'notes' => null,
                    ],
                );

                app(KitchenTicketService::class)->syncTicketItemsFromOrder($order->fresh(['items']));

                $ticket = KitchenTicket::query()
                    ->where('order_id', $order->id)
                    ->where('production_station_id', $kitchenStation->id)
                    ->first();

                if ($ticket !== null) {
                    $ticket->status = $status;
                    $ticket->queued_at = $orderedAt;
                    $ticket->started_at = in_array($status, ['preparing', 'ready', 'served'], true) ? $orderedAt->addMinutes(2) : null;
                    $ticket->ready_at = in_array($status, ['ready', 'served'], true) ? $orderedAt->addMinutes(8) : null;
                    $ticket->served_at = $status === 'served' ? $orderedAt->addMinutes(12) : null;
                    $ticket->save();

                    DB::table('kitchen_ticket_items')
                        ->where('kitchen_ticket_id', $ticket->id)
                        ->update(['status' => $status]);
                }

                $index++;
            }
        }
    }

    private static function seedSoundAlertOrders(): void
    {
        $kitchenTicketService = app(KitchenTicketService::class);

        foreach (DemoSeederContext::outlets() as $outlet) {
            for ($i = 1; $i <= 3; $i++) {
                $orderCode = "{$outlet->code}-SOUND-ALERT-{$i}";
                $menuItem = MenuItem::query()
                    ->where('outlet_id', $outlet->id)
                    ->where('name', 'Es Teh Manis')
                    ->first();

                if ($menuItem === null) {
                    continue;
                }

                $price = (float) $menuItem->price;
                $orderedAt = CarbonImmutable::now()->subMinutes(20 - ($i * 3));

                $order = Order::query()->updateOrCreate(
                    ['code' => $orderCode],
                    [
                        'tenant_id' => null,
                        'outlet_id' => $outlet->id,
                        'source' => 'qr',
                        'order_channel' => 'qr',
                        'service_mode' => 'dine_in',
                        'order_type' => 'dine_in',
                        'status' => 'confirmed',
                        'payment_status' => 'unpaid',
                        'kitchen_status' => 'queued',
                        'subtotal' => $price,
                        'tax' => 0,
                        'total' => $price,
                        'paid_total' => 0,
                        'balance_due' => $price,
                        'customer_name' => "Sound Alert Guest {$i}",
                        'confirmed_at' => $orderedAt,
                    ],
                );

                OrderItem::query()->updateOrCreate(
                    ['order_id' => $order->id, 'item_id' => "{$menuItem->id}-SND"],
                    [
                        'name' => $menuItem->name,
                        'emoji' => $menuItem->emoji,
                        'qty' => 1,
                        'price' => $menuItem->price,
                        'line_total' => $menuItem->price,
                        'notes' => null,
                    ],
                );

                $kitchenTicketService->syncTicketItemsFromOrder($order->fresh(['items']));
            }
        }
    }

}
