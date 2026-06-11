<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Jobs\Print\ProcessPrintJob;
use App\Modules\Print\Services\PrinterRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\PrinterStationTestFixture;
use Tests\TestCase;

class PrinterStationFallbackTest extends TestCase
{
    use RefreshDatabase;
    use PrinterStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_category_only_menu_item_still_routes_using_legacy_category_mapping(): void
    {
        $outlet = $this->createOutlet();
        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-cat', 'kitchen');
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'printer_profile_id' => $kitchenProfile->id,
            'print_type' => 'kitchen',
            'route_scope' => 'category',
            'category' => 'Food',
            'station' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Legacy Food',
            'category' => 'Food',
            'production_station_id' => null,
            'price' => 10000,
            'available' => true,
        ]);

        $order = $this->createOrderWithMenuItems($outlet, [$menuItem]);
        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order);

        $this->assertSame(1, PrintJob::query()->where('type', 'kitchen')->count());
        $job = PrintJob::query()->firstOrFail();
        $this->assertSame('category_mapping', data_get($job->route_snapshot, 'resolution_layer'));
    }

    public function test_item_level_route_override_still_wins_over_station_route(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-override', 'kitchen');
        $barProfile = $this->createKitchenProfile($outlet, 'bar-override', 'bar');
        $this->createStationRoute($outlet, $kitchenProfile, $stations['kitchen']);
        $this->createStationRoute($outlet, $barProfile, $stations['bar']);

        $special = $this->createMenuItemForStation($outlet, 'Special Grill', $stations['kitchen']);
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'printer_profile_id' => $barProfile->id,
            'print_type' => 'kitchen',
            'route_scope' => 'item',
            'item_id' => $special->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $order = $this->createOrderWithMenuItems($outlet, [$special]);
        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order);

        $job = PrintJob::query()->where('type', 'kitchen')->firstOrFail();
        $this->assertSame((int) $barProfile->id, (int) $job->printer_profile_id);
        $this->assertSame('item_override', data_get($job->route_snapshot, 'resolution_layer'));
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'ps-fallback-'.uniqid(),
            'name' => 'Printer Fallback Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }
}
