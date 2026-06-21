<?php

namespace Tests\Feature;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Print\Services\PrinterRoutingService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\PrinterStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PayNowKitchenPrintParityTest extends TestCase
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

    public function test_patch_cart_then_pay_queues_kitchen_for_updated_items(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-patch', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);

        $initialItem = $this->createMenuItemForStation($outlet, 'Es Teh', $stations['bar'] ?? $stations['kitchen']);
        $updatedItem = $this->createMenuItemForStation($outlet, 'Mojito Mocktail', $stations['bar'] ?? $stations['kitchen']);

        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'POS-PATCH-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $initialItem->id, 'name' => $initialItem->name, 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'payments' => [],
            'confirmedAt' => now()->toISOString(),
        ]);
        $create->assertCreated();
        $orderId = (int) $create->json('data.id');

        $this->assertSame(1, PrintJob::query()->where('type', 'kitchen')->where('source_id', $orderId)->count());

        $patch = $this->patchJson("/api/v1/orders/{$orderId}", [
            'items' => [
                ['id' => (string) $updatedItem->id, 'name' => $updatedItem->name, 'qty' => 1, 'price' => 12000],
            ],
            'subtotal' => 12000,
            'tax' => 0,
            'total' => 12000,
        ]);
        $patch->assertOk();

        $kitchenJobs = PrintJob::query()
            ->where('type', 'kitchen')
            ->where('source_id', $orderId)
            ->orderByDesc('id')
            ->get();

        $this->assertGreaterThanOrEqual(2, $kitchenJobs->count());

        $latestSnapshot = (array) ($kitchenJobs->first()?->printable_snapshot ?? []);
        $latestItemNames = collect($latestSnapshot['items'] ?? [])->pluck('name')->all();
        $this->assertContains($updatedItem->name, $latestItemNames);

        $pay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 12000],
            ],
        ]);
        $pay->assertOk();

        $this->assertGreaterThanOrEqual(2, PrintJob::query()->where('type', 'kitchen')->where('source_id', $orderId)->count());
        $this->assertGreaterThanOrEqual(1, PrintJob::query()->where('type', 'receipt')->count());
    }

    public function test_sync_kitchen_redispatches_dead_letter_job_for_same_snapshot(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-dead', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);

        $menuItem = $this->createMenuItemForStation($outlet, 'Nasi Goreng', $stations['kitchen']);
        $order = $this->createOrderWithMenuItems($outlet, [$menuItem]);

        $routing = app(PrinterRoutingService::class);
        $routing->syncKitchenPrintJobsForOrder($order);

        $job = PrintJob::query()->where('type', 'kitchen')->where('source_id', $order->id)->firstOrFail();
        $job->update([
            'status' => 'failed',
            'retryable' => false,
            'recovery_state' => 'dead_letter',
            'last_error' => 'LAN socket timeout',
        ]);

        $routing->syncKitchenPrintJobsForOrder($order->fresh(['items']));

        $job->refresh();
        $this->assertSame('pending', (string) $job->status);
        $this->assertSame('none', (string) $job->recovery_state);
        $this->assertTrue((bool) $job->retryable);
    }

    public function test_resume_open_bill_payment_still_syncs_kitchen_jobs(): void
    {
        $outlet = $this->createOutlet();
        $stations = $this->provisionPrintStations($outlet);

        $kitchenProfile = $this->createKitchenProfile($outlet, 'kitchen-resume', 'kitchen');
        $foodCategory = $this->ensureMenuCategory('Food');
        $this->createCategoryMapping($outlet, $foodCategory, $kitchenProfile);

        $menuItem = $this->createMenuItemForStation($outlet, 'Kentang Goreng', $stations['kitchen']);
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
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'code' => 'paynow-kitchen-'.uniqid(),
            'name' => 'Pay Now Kitchen Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }
}
