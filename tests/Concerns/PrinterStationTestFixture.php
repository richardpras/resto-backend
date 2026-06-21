<?php

namespace Tests\Concerns;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Production\Services\ProductionStationProvisioner;
use Illuminate\Support\Str;

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

    protected function ensureMenuCategory(string $name): MenuCategory
    {
        $existing = MenuCategory::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();
        if ($existing instanceof MenuCategory) {
            return $existing;
        }

        return MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => Str::slug(strtolower($name), '_') ?: 'category',
            'name' => $name,
            'name_en' => $name,
            'name_id' => $name,
            'is_active' => true,
        ]);
    }

    protected function createCategoryMapping(
        Outlet $outlet,
        MenuCategory $category,
        PrinterProfile $profile,
        int $priority = 10,
    ): MenuCategoryPrinterMapping {
        return MenuCategoryPrinterMapping::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'menu_category_id' => (int) $category->id,
            'printer_profile_id' => (int) $profile->id,
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
        $menuCategory = $this->ensureMenuCategory($category);

        return MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => $name,
            'category' => $category,
            'menu_category_id' => (int) $menuCategory->id,
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
