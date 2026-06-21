<?php

namespace Tests\Feature;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Models\User;
use App\Modules\Print\Services\PrinterRoutingService;
use App\Modules\Print\Services\SettingPrinterSyncService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Laravel\Passport\Passport;
use Tests\Concerns\PrinterStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class CashierPrinterReceiptRoutingTest extends TestCase
{
    use RefreshDatabase;
    use PrinterStationTestFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_receipt_uses_settings_cashier_over_demo_route_priority(): void
    {
        $outlet = $this->createOutlet();
        $demoProfile = $this->seedDemoCashierRoute($outlet);
        $settingsProfile = $this->seedCashierSettingPrinter($outlet, 'cashier-primary', now()->subDay());

        $order = $this->createPaidOrder($outlet);
        app(PrinterRoutingService::class)->queueReceiptForOrder($order, 'order-paid');

        $receiptJob = PrintJob::query()->where('type', 'receipt')->firstOrFail();
        $this->assertSame((int) $settingsProfile->id, (int) $receiptJob->printer_profile_id);
        $this->assertNotSame((int) $demoProfile->id, (int) $receiptJob->printer_profile_id);
    }

    public function test_receipt_uses_earliest_created_cashier_setting_printer(): void
    {
        $outlet = $this->createOutlet();
        $this->seedDemoCashierRoute($outlet);

        $olderProfile = $this->seedCashierSettingPrinter($outlet, 'cashier-older', now()->subDays(2));
        $newerProfile = $this->seedCashierSettingPrinter($outlet, 'cashier-newer', now()->subDay());

        $order = $this->createPaidOrder($outlet);
        app(PrinterRoutingService::class)->queueReceiptForOrder($order, 'order-paid');

        $receiptJob = PrintJob::query()->where('type', 'receipt')->firstOrFail();
        $this->assertSame((int) $olderProfile->id, (int) $receiptJob->printer_profile_id);
        $this->assertNotSame((int) $newerProfile->id, (int) $receiptJob->printer_profile_id);
    }

    public function test_pay_now_flow_creates_kitchen_and_receipt_print_jobs(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $this->seedCashierSettingPrinter($outlet, 'cashier-paynow', now()->subDay());

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-paynow', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);

        $menuItem = $this->createMenuItemForStation($outlet, 'Nasi Goreng', $stations['kitchen']);
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'POS-PAYNOW-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $menuItem->id, 'name' => $menuItem->name, 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
            'confirmedAt' => now()->toISOString(),
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');

        $pay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 10000],
            ],
        ]);
        $pay->assertOk();
        $pay->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertGreaterThanOrEqual(1, PrintJob::query()->where('type', 'kitchen')->where('source_id', $orderId)->count());
        $this->assertGreaterThanOrEqual(1, PrintJob::query()->where('type', 'receipt')->count());
    }

    public function test_finalize_paid_order_queues_kitchen_jobs_when_missing(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);
        $this->seedCashierSettingPrinter($outlet, 'cashier-safety', now()->subDay());

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-safety', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);

        $menuItem = $this->createMenuItemForStation($outlet, 'Es Teh', $stations['bar'] ?? $stations['kitchen']);
        $order = $this->createOrderWithMenuItems($outlet, [$menuItem]);

        $this->assertSame(0, PrintJob::query()->where('type', 'kitchen')->where('source_id', $order->id)->count());

        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $pay = $this->postJson("/api/v1/orders/{$order->id}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => (float) $order->total],
            ],
        ]);
        $pay->assertOk();

        $this->assertGreaterThanOrEqual(1, PrintJob::query()->where('type', 'kitchen')->where('source_id', $order->id)->count());
        $this->assertGreaterThanOrEqual(1, PrintJob::query()->where('type', 'receipt')->count());
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'cashier-route-'.uniqid(),
            'name' => 'Cashier Route Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }

    private function seedDemoCashierRoute(Outlet $outlet): PrinterProfile
    {
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'B-CASHIER',
            'name' => 'Demo Cashier Receipt Printer',
            'station' => 'cashier',
            'connection_type' => 'bridge',
            'ip_address' => '10.10.2.21',
            'is_active' => true,
        ]);

        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'printer_profile_id' => $profile->id,
            'print_type' => 'receipt',
            'route_scope' => 'default',
            'station' => 'cashier',
            'priority' => 10,
            'is_active' => true,
        ]);

        return $profile;
    }

    private function seedCashierSettingPrinter(Outlet $outlet, string $id, Carbon $createdAt): PrinterProfile
    {
        $setting = SettingPrinter::query()->create([
            'id' => $id,
            'name' => 'Cashier Printer '.$id,
            'printer_type' => 'cashier',
            'connection' => 'shared',
            'ip' => 'Kasir',
            'bluetooth_device' => '\\\\127.0.0.1\\Kasir-Printer',
            'outlet_id' => $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => null,
        ]);
        $setting->created_at = $createdAt;
        $setting->updated_at = $createdAt;
        $setting->saveQuietly();

        return app(SettingPrinterSyncService::class)->syncFromSettingPrinter($setting->fresh());
    }

    private function createPaidOrder(Outlet $outlet): Order
    {
        return Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'RCP-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 10000,
            'balance_due' => 0,
        ]);
    }
}
