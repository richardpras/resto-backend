<?php

namespace Tests\Feature;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederCashierStationItemTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_cashier_station_items_skip_kds_tickets(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $order = Order::query()->where('code', 'DEMO-SUNSET-STATION-SHOWCASE')->firstOrFail();

        $cashierStation = ProductionStation::query()
            ->where('outlet_id', $outlet->id)
            ->where('code', 'cashier')
            ->firstOrFail();

        $this->assertFalse((bool) $cashierStation->kds_enabled);
        $this->assertFalse((bool) $cashierStation->print_enabled);

        $this->assertSame(0, KitchenTicket::query()
            ->where('order_id', $order->id)
            ->where('station_code', 'cashier')
            ->count());
    }

    public function test_cashier_items_assigned_to_cashier_station(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $cashierStation = ProductionStation::query()
            ->where('outlet_id', $outlet->id)
            ->where('code', 'cashier')
            ->firstOrFail();

        $item = MenuItem::query()
            ->where('outlet_id', $outlet->id)
            ->where('name', 'Rokok Marlboro')
            ->firstOrFail();

        $this->assertSame((int) $cashierStation->id, (int) $item->production_station_id);
    }

    public function test_no_kitchen_print_job_for_cashier_station_items(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $order = Order::query()->where('code', 'DEMO-SUNSET-STATION-SHOWCASE')->firstOrFail();

        $kitchenJobs = PrintJob::query()
            ->where('source_id', $order->id)
            ->where('type', 'kitchen')
            ->get();

        foreach ($kitchenJobs as $job) {
            $names = collect($job->printable_snapshot['items'] ?? [])->pluck('name')->all();
            $this->assertNotContains('Rokok Marlboro', $names);
        }
    }
}
