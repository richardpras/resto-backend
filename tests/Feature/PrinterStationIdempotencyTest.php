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

class PrinterStationIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use PrinterStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_re_running_queue_kitchen_tickets_does_not_duplicate_identical_category_jobs(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-idem', 'kitchen');
        $barProfile = $this->createKitchenProfile($outlet, 'bar-idem', 'bar');
        $foodCategory = $this->ensureMenuCategory('Food');
        $beverageCategory = $this->ensureMenuCategory('Beverage');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);
        $this->createCategoryMapping($outlet, $beverageCategory, $barProfile);

        $nasi = $this->createMenuItemForStation($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createMenuItemForStation($outlet, 'Es Teh', $stations['bar'], 'Beverage');
        $order = $this->createOrderWithMenuItems($outlet, [$nasi, $esTeh]);

        $routing = app(PrinterRoutingService::class);
        $routing->queueKitchenTicketsForOrder($order);
        $routing->queueKitchenTicketsForOrder($order);

        $this->assertSame(2, PrintJob::query()->where('outlet_id', $outlet->id)->where('type', 'kitchen')->count());
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'ps-idem-'.uniqid(),
            'name' => 'Printer Idempotency Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }
}
