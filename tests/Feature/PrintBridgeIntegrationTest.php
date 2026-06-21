<?php

namespace Tests\Feature;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Hardware\Services\HardwareBridgeService;
use App\Modules\Hardware\Support\HardwareCommandType;
use App\Modules\Print\Services\PrintQueueProcessingService;
use App\Modules\Print\Services\PrinterRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use App\Jobs\Print\ProcessPrintJob;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PrintBridgeIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use AccountingRemediationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['queue.default' => 'sync']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_print_job_dispatches_hardware_command_and_completes_on_ack(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('PB-01');
        $device = HardwareBridgeDevice::query()->create([
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-'.$outlet->id,
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'cashier-main',
            'name' => 'Cashier',
            'station' => 'cashier',
            'connection_type' => 'lan',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
            'meta' => ['bridge' => ['deviceKey' => $device->device_key], 'lan' => ['port' => 9100]],
        ]);
        $route = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $profile->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);

        $job = app(PrinterRoutingService::class)->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 42,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['thermalText' => "RECEIPT\nOrder #42\nTotal: 10000"],
            idempotencyKey: 'pb-int-42',
        );

        app(PrintQueueProcessingService::class)->processJob((int) $job->id, (int) $outlet->id);
        $dispatched = $job->fresh();
        $this->assertSame('awaiting_ack', (string) $dispatched->recovery_state);
        $this->assertNotNull($dispatched->hardware_command_log_id);
        $this->assertDatabaseHas('hardware_command_logs', [
            'id' => (int) $dispatched->hardware_command_log_id,
            'command_type' => HardwareCommandType::PRINT_DOCUMENT,
            'status' => 'pending',
        ]);

        $commandId = (int) $dispatched->hardware_command_log_id;
        app(HardwareBridgeService::class)->acknowledgeCommand($user, $commandId, true, [
            'ackPayload' => ['result' => 'printed'],
        ]);

        $this->assertSame('done', (string) $job->fresh()->status);
        $this->assertDatabaseHas('hardware_command_logs', [
            'id' => $commandId,
            'status' => 'acknowledged',
        ]);
    }

    public function test_kitchen_routing_splits_food_and_beverage_without_duplicates(): void
    {
        Bus::fake([ProcessPrintJob::class]);

        $outlet = Outlet::query()->create([
            'code' => 'pb-02-'.uniqid(),
            'name' => 'Routing Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $foodCategory = \App\Models\Modules\Menu\Domain\MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => 'food',
            'name' => 'Food',
            'is_active' => true,
        ]);
        $drinkCategory = \App\Models\Modules\Menu\Domain\MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => 'beverage',
            'name' => 'Beverage',
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
        \App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'menu_category_id' => (int) $foodCategory->id,
            'printer_profile_id' => (int) $kitchen->id,
            'priority' => 10,
            'is_active' => true,
        ]);
        \App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'menu_category_id' => (int) $drinkCategory->id,
            'printer_profile_id' => (int) $bar->id,
            'priority' => 10,
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'ORD-PB-02',
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
    }
}
