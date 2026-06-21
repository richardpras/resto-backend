<?php

namespace Tests\Feature;

use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Jobs\Print\ProcessPrintJob;
use App\Modules\Print\Services\PrinterRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\PrinterStationTestFixture;
use Tests\TestCase;

class PrintReceiptRegressionTest extends TestCase
{
    use RefreshDatabase;
    use PrinterStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_receipt_print_still_queues_for_paid_order_and_is_unaffected_by_station_skip(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $receiptProfile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'cashier-receipt',
            'name' => 'Receipt Printer',
            'station' => 'cashier',
            'connection_type' => 'lan',
            'is_active' => true,
        ]);
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'printer_profile_id' => $receiptProfile->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-receipt', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);

        $rokok = $this->createMenuItemForStation($outlet, 'Rokok Marlboro', $stations['cashier'], 'Retail');
        $nasi = $this->createMenuItemForStation($outlet, 'Nasi Goreng', $stations['kitchen']);
        $order = $this->createOrderWithMenuItems($outlet, [$nasi, $rokok]);
        $order->update(['paid_total' => 20000, 'payment_status' => 'paid']);

        $routing = app(PrinterRoutingService::class);
        $routing->queueKitchenTicketsForOrder($order->fresh(['items']));
        $routing->queueReceiptForOrder($order, 'order-paid');

        $this->assertSame(1, PrintJob::query()->where('type', 'kitchen')->count());
        $this->assertSame(1, PrintJob::query()->where('type', 'receipt')->count());

        $receiptJob = PrintJob::query()->where('type', 'receipt')->firstOrFail();
        $this->assertSame('receipt', $receiptJob->type);
        $this->assertSame((int) $order->id, (int) $receiptJob->source_id);
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'ps-receipt-'.uniqid(),
            'name' => 'Receipt Regression Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }
}
