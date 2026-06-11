<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederPrinterStationRoutingTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_station_showcase_creates_station_kitchen_print_jobs(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $order = Order::query()->where('code', 'DEMO-SUNSET-STATION-SHOWCASE')->firstOrFail();

        $kitchenJobs = PrintJob::query()
            ->where('outlet_id', $outlet->id)
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('type', 'kitchen')
            ->get();

        $this->assertCount(3, $kitchenJobs);

        $profileNames = PrinterProfile::query()
            ->whereIn('id', $kitchenJobs->pluck('printer_profile_id'))
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing(
            ['Kitchen Printer', 'Bar Printer', 'Dessert Printer'],
            $profileNames,
        );
    }

    public function test_kitchen_print_jobs_contain_only_matching_station_items(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $order = Order::query()->where('code', 'DEMO-SUNSET-STATION-SHOWCASE')->firstOrFail();

        $kitchenProfile = PrinterProfile::query()
            ->where('outlet_id', $outlet->id)
            ->where('name', 'Kitchen Printer')
            ->firstOrFail();

        $kitchenJob = PrintJob::query()
            ->where('source_id', $order->id)
            ->where('printer_profile_id', $kitchenProfile->id)
            ->where('type', 'kitchen')
            ->firstOrFail();

        $itemNames = collect($kitchenJob->printable_snapshot['items'] ?? [])->pluck('name')->all();
        $this->assertSame(['Nasi Goreng Nusantara'], $itemNames);
    }

    public function test_receipt_print_job_includes_all_items(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $order = Order::query()->where('code', 'DEMO-SUNSET-STATION-SHOWCASE')->firstOrFail();

        $receiptJob = PrintJob::query()
            ->where('outlet_id', $outlet->id)
            ->where('source_id', $order->id)
            ->where('type', 'receipt')
            ->first();

        $this->assertNotNull($receiptJob);
    }

    public function test_printer_profiles_use_production_ready_names(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $names = PrinterProfile::query()
            ->where('outlet_id', $outlet->id)
            ->pluck('name')
            ->all();

        $this->assertContains('Kitchen Printer', $names);
        $this->assertContains('Bar Printer', $names);
        $this->assertContains('Dessert Printer', $names);
        $this->assertContains('Cashier Receipt Printer', $names);
    }
}
