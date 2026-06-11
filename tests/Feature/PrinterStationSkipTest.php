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

class PrinterStationSkipTest extends TestCase
{
    use RefreshDatabase;
    use PrinterStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_cashier_station_with_print_disabled_creates_no_kitchen_print_job(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-skip', 'kitchen');
        $this->createStationRoute($outlet, $kitchenProfile, $stations['kitchen']);

        $rokok = $this->createMenuItemForStation($outlet, 'Rokok Marlboro', $stations['cashier'], 'Retail');
        $order = $this->createOrderWithMenuItems($outlet, [$rokok]);

        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order);

        $this->assertSame(0, PrintJob::query()->where('outlet_id', $outlet->id)->where('type', 'kitchen')->count());
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'ps-skip-'.uniqid(),
            'name' => 'Printer Skip Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }
}
