<?php

namespace Tests\Concerns;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Production\Services\ProductionStationProvisioner;

trait PrinterStationTestFixture
{
    /** @return array<string, ProductionStation> */
    protected function provisionPrintStations(Outlet $outlet): array
    {
        $stations = app(ProductionStationProvisioner::class)->ensureForOutlet($outlet, null, 1);
        $indexed = [];
        foreach ($stations as $station) {
            $indexed[(string) $station->code] = $station;
        }

        return $indexed;
    }

    protected function createKitchenProfile(Outlet $outlet, string $code, string $station): PrinterProfile
    {
        return PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => $code,
            'name' => ucfirst($station).' Printer',
            'station' => $station,
            'connection_type' => 'lan',
            'ip_address' => '10.0.0.1',
            'is_active' => true,
        ]);
    }

    protected function createStationRoute(
        Outlet $outlet,
        PrinterProfile $profile,
        ProductionStation $station,
        int $priority = 10,
    ): PrinterRoute {
        return PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'printer_profile_id' => $profile->id,
            'print_type' => 'kitchen',
            'route_scope' => 'production_station',
            'production_station_id' => $station->id,
            'station_code' => strtolower((string) $station->code),
            'station' => strtolower((string) $station->code),
            'priority' => $priority,
            'is_active' => true,
        ]);
    }

    protected function createMenuItemForStation(
        Outlet $outlet,
        string $name,
        ProductionStation $station,
        string $category = 'Food',
    ): MenuItem {
        return MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => $name,
            'category' => $category,
            'production_station_id' => $station->id,
            'price' => 10000,
            'available' => true,
        ]);
    }

    protected function createOrderWithMenuItems(Outlet $outlet, array $menuItems): Order
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'PR-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 10000 * count($menuItems),
            'tax' => 0,
            'total' => 10000 * count($menuItems),
            'paid_total' => 0,
            'balance_due' => 10000 * count($menuItems),
        ]);

        foreach ($menuItems as $menuItem) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'qty' => 1,
                'price' => 10000,
                'line_total' => 10000,
            ]);
        }

        return $order->fresh(['items']);
    }
}
