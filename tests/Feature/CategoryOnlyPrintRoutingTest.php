<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Print\Services\PrinterRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use App\Jobs\Print\ProcessPrintJob;
use Tests\TestCase;

class CategoryOnlyPrintRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessPrintJob::class]);
        config(['print.category_mapping.enabled' => true]);
        config(['print.legacy_routing.enabled' => false]);
    }

    public function test_kitchen_print_routes_by_category_mapping_only(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'cat-only-1',
            'name' => 'Category Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);

        $foodCategory = MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => 'food',
            'name' => 'Food',
            'name_en' => 'Food',
            'name_id' => 'Makanan',
            'is_active' => true,
        ]);
        $drinkCategory = MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => 'beverage',
            'name' => 'Beverage',
            'name_en' => 'Beverage',
            'name_id' => 'Minuman',
            'is_active' => true,
        ]);

        $kitchen = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'kitchen',
            'name' => 'Kitchen',
            'station' => 'kitchen',
            'connection_type' => 'lan',
            'ip_address' => '10.0.0.2',
            'is_active' => true,
        ]);
        $bar = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'bar',
            'name' => 'Bar',
            'station' => 'bar',
            'connection_type' => 'lan',
            'ip_address' => '10.0.0.3',
            'is_active' => true,
        ]);

        MenuCategoryPrinterMapping::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'menu_category_id' => (int) $foodCategory->id,
            'printer_profile_id' => (int) $kitchen->id,
            'priority' => 10,
            'is_active' => true,
        ]);
        MenuCategoryPrinterMapping::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'menu_category_id' => (int) $drinkCategory->id,
            'printer_profile_id' => (int) $bar->id,
            'priority' => 10,
            'is_active' => true,
        ]);

        $foodItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'Burger',
            'category' => 'Food',
            'menu_category_id' => (int) $foodCategory->id,
            'price' => 50,
            'available' => true,
        ]);
        $drinkItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'Cola',
            'category' => 'Beverage',
            'menu_category_id' => (int) $drinkCategory->id,
            'price' => 20,
            'available' => true,
        ]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'ORD-CAT-01',
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 100,
            'tax' => 10,
            'total' => 110,
            'paid_total' => 0,
            'balance_due' => 110,
        ]);
        OrderItem::query()->create([
            'order_id' => (int) $order->id,
            'item_id' => (int) $foodItem->id,
            'name' => 'Burger',
            'qty' => 1,
            'price' => 50,
            'line_total' => 50,
        ]);
        OrderItem::query()->create([
            'order_id' => (int) $order->id,
            'item_id' => (int) $drinkItem->id,
            'name' => 'Cola',
            'qty' => 2,
            'price' => 20,
            'line_total' => 40,
        ]);

        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order->fresh(['items']));

        $jobs = PrintJob::query()->where('outlet_id', (int) $outlet->id)->where('type', 'kitchen')->get();
        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing(
            [(int) $kitchen->id, (int) $bar->id],
            $jobs->pluck('printer_profile_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame(
            'category_master_mapping',
            (string) data_get($jobs->first()->route_snapshot, 'resolution_layer')
        );
    }

    public function test_unmapped_category_skips_kitchen_print_job(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'cat-only-2',
            'name' => 'Unmapped Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $category = MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => 'snacks',
            'name' => 'Snacks',
            'is_active' => true,
        ]);
        $item = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'name' => 'Chips',
            'category' => 'Snacks',
            'menu_category_id' => (int) $category->id,
            'price' => 10,
            'available' => true,
        ]);
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'ORD-CAT-02',
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 10,
            'tax' => 1,
            'total' => 11,
            'paid_total' => 0,
            'balance_due' => 11,
        ]);
        OrderItem::query()->create([
            'order_id' => (int) $order->id,
            'item_id' => (int) $item->id,
            'name' => 'Chips',
            'qty' => 1,
            'price' => 10,
            'line_total' => 10,
        ]);

        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order->fresh(['items']));

        $this->assertSame(0, PrintJob::query()->where('outlet_id', (int) $outlet->id)->where('type', 'kitchen')->count());
    }
}
