<?php

namespace Tests\Feature;

use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Jobs\Print\ProcessPrintJob;
use App\Modules\Print\Services\PrinterRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\PrinterStationTestFixture;
use Tests\TestCase;

class PrinterStationRoutingTest extends TestCase
{
    use RefreshDatabase;
    use PrinterStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_mixed_station_order_creates_three_kitchen_print_jobs(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-main', 'kitchen');
        $barProfile = $this->createKitchenProfile($outlet, 'bar-main', 'bar');
        $bakeryProfile = $this->createKitchenProfile($outlet, 'bakery-main', 'bakery');

        $this->createStationRoute($outlet, $kitchenProfile, $stations['kitchen']);
        $this->createStationRoute($outlet, $barProfile, $stations['bar']);
        $this->createStationRoute($outlet, $bakeryProfile, $stations['bakery']);

        $nasi = $this->createMenuItemForStation($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItemForStation($outlet, 'Es Teh', $stations['bar'], 'Beverage');
        $croissant = $this->createMenuItemForStation($outlet, 'Croissant', $stations['bakery'], 'Dessert');
        $rokok = $this->createMenuItemForStation($outlet, 'Rokok Marlboro', $stations['cashier'], 'Retail');

        $order = $this->createOrderWithMenuItems($outlet, [$nasi, $esTeh, $croissant, $rokok]);
        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order);

        $jobs = PrintJob::query()->where('outlet_id', $outlet->id)->where('type', 'kitchen')->get();
        $this->assertCount(3, $jobs);
        $this->assertEqualsCanonicalizing(
            [(int) $kitchenProfile->id, (int) $barProfile->id, (int) $bakeryProfile->id],
            $jobs->pluck('printer_profile_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_each_print_job_contains_only_matching_station_items(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-items', 'kitchen');
        $barProfile = $this->createKitchenProfile($outlet, 'bar-items', 'bar');
        $this->createStationRoute($outlet, $kitchenProfile, $stations['kitchen']);
        $this->createStationRoute($outlet, $barProfile, $stations['bar']);

        $nasi = $this->createMenuItemForStation($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItemForStation($outlet, 'Es Teh', $stations['bar'], 'Beverage');
        $order = $this->createOrderWithMenuItems($outlet, [$nasi, $esTeh]);

        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order);

        $kitchenJob = PrintJob::query()->where('printer_profile_id', $kitchenProfile->id)->firstOrFail();
        $barJob = PrintJob::query()->where('printer_profile_id', $barProfile->id)->firstOrFail();
        $kitchenItems = collect($kitchenJob->printable_snapshot['items'] ?? [])->pluck('name')->all();
        $barItems = collect($barJob->printable_snapshot['items'] ?? [])->pluck('name')->all();

        $this->assertSame(['Nasi Goreng'], $kitchenItems);
        $this->assertSame(['Es Teh'], $barItems);
        $this->assertSame('kitchen', data_get($kitchenJob->route_snapshot, 'stationCode'));
        $this->assertSame('production_station', data_get($kitchenJob->route_snapshot, 'resolution_layer'));
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'ps-route-'.uniqid(),
            'name' => 'Printer Station Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }
}
