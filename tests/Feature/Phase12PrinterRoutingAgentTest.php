<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Print\Services\PrinterRoutingService;
use App\Modules\Print\Services\PrintQueueProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase12PrinterRoutingAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_print_protection_is_idempotent_for_same_order_route(): void
    {
        $outlet = $this->createOutlet('P12-PA');
        $profile = $this->createProfile((int) $outlet->id, 'kitchen-main');
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $profile->id,
            'print_type' => 'kitchen',
            'category' => 'food',
            'priority' => 1,
            'is_active' => true,
        ]);

        $order = $this->createOrderWithCategorizedItem((int) $outlet->id, 'food');
        $routing = app(PrinterRoutingService::class);

        $routing->queueKitchenTicketsForOrder($order->fresh(['items']));
        $routing->queueKitchenTicketsForOrder($order->fresh(['items']));

        $this->assertEquals(
            1,
            PrintJob::query()->where('outlet_id', $outlet->id)->where('source_id', $order->id)->where('type', 'kitchen')->count()
        );
    }

    public function test_printer_retry_safety_marks_failed_job_recoverable_then_dead_letter(): void
    {
        $outlet = $this->createOutlet('P12-RS');
        $profile = $this->createProfile((int) $outlet->id, 'retry-main');
        $route = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $profile->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);
        $routing = app(PrinterRoutingService::class);
        $job = $routing->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 88,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['simulate_failure' => true],
            idempotencyKey: 'retry-safety-88'
        );
        $job->max_attempts = 2;
        $job->save();

        $processor = app(PrintQueueProcessingService::class);
        $processor->processJob((int) $job->id, (int) $outlet->id);
        $failedRecoverable = $job->fresh();
        $this->assertSame('failed', (string) $failedRecoverable->status);
        $this->assertTrue((bool) $failedRecoverable->retryable);
        $this->assertSame('recoverable', (string) $failedRecoverable->recovery_state);

        $failedRecoverable->next_retry_at = now()->subSecond();
        $failedRecoverable->save();
        $processor->processJob((int) $job->id, (int) $outlet->id);
        $failedDeadLetter = $job->fresh();
        $this->assertFalse((bool) $failedDeadLetter->retryable);
        $this->assertSame('dead_letter', (string) $failedDeadLetter->recovery_state);
    }

    public function test_print_queue_recovery_retry_action_requeues_job_safely(): void
    {
        $outlet = $this->createOutlet('P12-QR');
        $profile = $this->createProfile((int) $outlet->id, 'retry-action-main');
        $route = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $profile->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);

        $routing = app(PrinterRoutingService::class);
        $job = $routing->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 99,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['simulate_failure' => true],
            idempotencyKey: 'retry-action-99'
        );

        $processor = app(PrintQueueProcessingService::class);
        $processor->processJob((int) $job->id, (int) $outlet->id);
        $this->assertSame('failed', (string) $job->fresh()->status);

        $retried = $processor->retryJob((int) $job->id, (int) $outlet->id);
        $this->assertSame('pending', (string) $retried->status);
        $this->assertTrue((bool) $retried->retryable);
        $this->assertNotNull($retried->next_retry_at);
    }

    public function test_outlet_isolation_applies_to_routes_and_jobs(): void
    {
        $outletA = $this->createOutlet('P12-OA');
        $outletB = $this->createOutlet('P12-OB');
        $profileA = $this->createProfile((int) $outletA->id, 'profile-a');
        $profileB = $this->createProfile((int) $outletB->id, 'profile-b');

        $routeA = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outletA->id,
            'printer_profile_id' => (int) $profileA->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);
        $routeB = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outletB->id,
            'printer_profile_id' => (int) $profileB->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);

        $routing = app(PrinterRoutingService::class);
        $jobA = $routing->enqueuePrintJob((int) $outletA->id, 'order', 501, 'receipt', $routeA, ['v' => 1], 'iso-501');
        $jobB = $routing->enqueuePrintJob((int) $outletB->id, 'order', 501, 'receipt', $routeB, ['v' => 1], 'iso-501');

        $this->assertNotEquals((int) $jobA->id, (int) $jobB->id);
        $this->assertSame((int) $outletA->id, (int) $jobA->outlet_id);
        $this->assertSame((int) $outletB->id, (int) $jobB->outlet_id);
        $statusBeforeWrongOutletAttempt = (string) $jobA->fresh()->status;

        $processor = app(PrintQueueProcessingService::class);
        $processor->processJob((int) $jobA->id, (int) $outletB->id);
        $this->assertSame($statusBeforeWrongOutletAttempt, (string) $jobA->fresh()->status);
    }

    public function test_kitchen_routing_resolution_hierarchy_prefers_item_then_category_then_station_then_default(): void
    {
        config(['print.category_station_map' => ['drinks' => 'bar']]);

        $outlet = $this->createOutlet('P12-HR');
        $defaultProfile = $this->createProfile((int) $outlet->id, 'default-main');
        $stationProfile = $this->createProfile((int) $outlet->id, 'station-bar');
        $categoryProfile = $this->createProfile((int) $outlet->id, 'category-drinks');
        $itemProfile = $this->createProfile((int) $outlet->id, 'item-override');

        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $defaultProfile->id,
            'print_type' => 'kitchen',
            'priority' => 100,
            'is_active' => true,
        ]);
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $stationProfile->id,
            'print_type' => 'kitchen',
            'station' => 'bar',
            'priority' => 50,
            'is_active' => true,
        ]);
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $categoryProfile->id,
            'print_type' => 'kitchen',
            'category' => 'drinks',
            'priority' => 20,
            'is_active' => true,
        ]);

        $order = $this->createOrderWithCategorizedItem((int) $outlet->id, 'drinks');
        $itemOverrideRoute = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $itemProfile->id,
            'print_type' => 'kitchen',
            'priority' => 10,
            'is_active' => true,
            'meta' => [
                'routeScope' => 'item',
                'itemId' => (int) $order->items()->firstOrFail()->item_id,
            ],
        ]);

        app(PrinterRoutingService::class)->queueKitchenTicketsForOrder($order->fresh(['items']));

        /** @var PrintJob $job */
        $job = PrintJob::query()->where('outlet_id', (int) $outlet->id)->where('source_id', (int) $order->id)->where('type', 'kitchen')->firstOrFail();
        $this->assertSame((int) $itemProfile->id, (int) $job->printer_profile_id);
        $this->assertSame((int) $itemOverrideRoute->id, (int) $job->printer_route_id);
        $this->assertSame('item_override', (string) data_get($job->printable_snapshot, 'route_resolution.resolution_layer'));
        $this->assertSame('drinks', (string) data_get($job->printable_snapshot, 'route_resolution.source_category'));
        $this->assertSame('bar', (string) data_get($job->printable_snapshot, 'route_resolution.resolved_station'));
    }

    public function test_routing_resolution_is_outlet_isolated_for_same_category(): void
    {
        config(['print.category_station_map' => ['drinks' => 'bar']]);

        $outletA = $this->createOutlet('P12-RA');
        $outletB = $this->createOutlet('P12-RB');
        $profileA = $this->createProfile((int) $outletA->id, 'drinks-a');
        $profileB = $this->createProfile((int) $outletB->id, 'drinks-b');

        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outletA->id,
            'printer_profile_id' => (int) $profileA->id,
            'print_type' => 'kitchen',
            'category' => 'drinks',
            'priority' => 5,
            'is_active' => true,
        ]);
        PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outletB->id,
            'printer_profile_id' => (int) $profileB->id,
            'print_type' => 'kitchen',
            'category' => 'drinks',
            'priority' => 5,
            'is_active' => true,
        ]);

        $orderA = $this->createOrderWithCategorizedItem((int) $outletA->id, 'drinks');
        $orderB = $this->createOrderWithCategorizedItem((int) $outletB->id, 'drinks');
        $routing = app(PrinterRoutingService::class);
        $routing->queueKitchenTicketsForOrder($orderA->fresh(['items']));
        $routing->queueKitchenTicketsForOrder($orderB->fresh(['items']));

        /** @var PrintJob $jobA */
        $jobA = PrintJob::query()->where('outlet_id', (int) $outletA->id)->where('source_id', (int) $orderA->id)->where('type', 'kitchen')->firstOrFail();
        /** @var PrintJob $jobB */
        $jobB = PrintJob::query()->where('outlet_id', (int) $outletB->id)->where('source_id', (int) $orderB->id)->where('type', 'kitchen')->firstOrFail();
        $this->assertSame((int) $profileA->id, (int) $jobA->printer_profile_id);
        $this->assertSame((int) $profileB->id, (int) $jobB->printer_profile_id);
    }

    private function createOutlet(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }

    private function createProfile(int $outletId, string $code): PrinterProfile
    {
        return PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => 'Profile '.uniqid(),
            'connection_type' => 'agent',
            'is_active' => true,
            'health_status' => 'unknown',
            'queue_state' => 'idle',
        ]);
    }

    private function createOrderWithCategorizedItem(int $outletId, string $category): Order
    {
        $menu = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'P12 Item '.uniqid(),
            'category' => $category,
            'price' => 10000,
            'available' => true,
        ]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'P12-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
        ]);
        OrderItem::query()->create([
            'order_id' => (int) $order->id,
            'item_id' => (int) $menu->id,
            'name' => (string) $menu->name,
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
        ]);

        return $order;
    }
}
