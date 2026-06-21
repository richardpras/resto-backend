<?php

namespace Tests\Feature;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Print\Services\PrintDispatchService;
use App\Modules\Print\Services\PrinterManagementService;
use App\Modules\Print\Services\PrinterRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PrintDispatchModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_worker_mode_dispatches_process_print_job_for_new_jobs(): void
    {
        Bus::fake([ProcessPrintJob::class]);
        config(['print.dispatch.mode' => PrintDispatchService::MODE_QUEUE_WORKER]);

        [$outlet, $route] = $this->createReceiptRoute('PDM-QW');

        app(PrinterRoutingService::class)->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 101,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['thermalText' => 'Receipt'],
            idempotencyKey: 'pdm-qw-101',
        );

        Bus::assertDispatched(ProcessPrintJob::class, 1);
    }

    public function test_sync_dispatch_mode_processes_job_without_queue(): void
    {
        Bus::fake([ProcessPrintJob::class]);
        config(['print.dispatch.mode' => PrintDispatchService::MODE_SYNC_DISPATCH]);

        [$outlet, $route, $device] = $this->createReceiptRouteWithBridge('PDM-SYNC');

        $job = app(PrinterRoutingService::class)->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 102,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['thermalText' => "Receipt\nOrder #102"],
            idempotencyKey: 'pdm-sync-102',
        );

        Bus::assertNothingDispatched();
        $fresh = $job->fresh();
        $this->assertSame('awaiting_ack', (string) $fresh->recovery_state);
        $this->assertGreaterThan(0, (int) $fresh->attempts);
        $this->assertNotNull($fresh->hardware_command_log_id);
        $this->assertDatabaseHas('hardware_command_logs', [
            'hardware_bridge_device_id' => (int) $device->id,
        ]);
    }

    public function test_scheduled_dispatch_mode_defers_processing_to_cron_command(): void
    {
        Bus::fake([ProcessPrintJob::class]);
        config(['print.dispatch.mode' => PrintDispatchService::MODE_SCHEDULED_DISPATCH]);

        [$outlet, $route, $device] = $this->createReceiptRouteWithBridge('PDM-SCH');

        $job = app(PrinterRoutingService::class)->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 103,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['thermalText' => "Receipt\nOrder #103"],
            idempotencyKey: 'pdm-sch-103',
        );

        Bus::assertNothingDispatched();
        $this->assertSame('pending', (string) $job->fresh()->status);
        $this->assertNull($job->fresh()->hardware_command_log_id);

        Artisan::call('print:process-pending', ['--outlet' => (string) $outlet->id]);

        $processed = $job->fresh();
        $this->assertSame('awaiting_ack', (string) $processed->recovery_state);
        $this->assertNotNull($processed->hardware_command_log_id);
        $this->assertDatabaseHas('hardware_command_logs', [
            'hardware_bridge_device_id' => (int) $device->id,
        ]);
    }

    public function test_duplicate_enqueue_does_not_redispatch_in_any_mode(): void
    {
        config(['print.dispatch.mode' => PrintDispatchService::MODE_SYNC_DISPATCH]);

        [$outlet, $route] = $this->createReceiptRouteWithBridge('PDM-IDEM');
        $routing = app(PrinterRoutingService::class);

        $routing->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 104,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['thermalText' => 'Receipt'],
            idempotencyKey: 'pdm-idem-104',
        );

        $commandCountAfterFirst = (int) \Illuminate\Support\Facades\DB::table('hardware_command_logs')->count();

        $routing->enqueuePrintJob(
            outletId: (int) $outlet->id,
            sourceType: 'order',
            sourceId: 104,
            type: 'receipt',
            route: $route,
            printableSnapshot: ['thermalText' => 'Receipt'],
            idempotencyKey: 'pdm-idem-104',
        );

        $this->assertSame(1, PrintJob::query()->where('outlet_id', (int) $outlet->id)->where('source_id', 104)->count());
        $this->assertSame($commandCountAfterFirst, (int) \Illuminate\Support\Facades\DB::table('hardware_command_logs')->count());
    }

    public function test_queue_status_exposes_dispatch_counters(): void
    {
        config(['print.dispatch.mode' => PrintDispatchService::MODE_SCHEDULED_DISPATCH]);

        [$outlet] = $this->createReceiptRouteWithBridge('PDM-STAT');

        PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'type' => 'receipt',
            'source_type' => 'order',
            'source_id' => 200,
            'idempotency_key' => 'stat-pending',
            'dedupe_key' => sha1('stat-pending'),
            'content' => [],
            'printable_snapshot' => [],
            'status' => 'pending',
            'attempts' => 2,
            'queued_at' => now(),
            'max_attempts' => 5,
            'retryable' => true,
            'recovery_state' => 'recoverable',
        ]);
        PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'type' => 'kitchen',
            'source_type' => 'order',
            'source_id' => 201,
            'idempotency_key' => 'stat-dead',
            'dedupe_key' => sha1('stat-dead'),
            'content' => [],
            'printable_snapshot' => [],
            'status' => 'failed',
            'attempts' => 5,
            'queued_at' => now(),
            'max_attempts' => 5,
            'retryable' => false,
            'recovery_state' => 'dead_letter',
            'failed_at' => now(),
        ]);

        $status = app(PrinterManagementService::class)->queueStatus((int) $outlet->id);

        $this->assertSame(PrintDispatchService::MODE_SCHEDULED_DISPATCH, $status['dispatchMode']);
        $this->assertSame(1, $status['pending']);
        $this->assertSame(1, $status['failed']);
        $this->assertSame(2, $status['retried']);
        $this->assertSame(1, $status['recoverable']);
        $this->assertSame(1, $status['deadLetter']);
    }

    /**
     * @return array{0:Outlet,1:PrinterRoute}
     */
    private function createReceiptRoute(string $suffix): array
    {
        $outlet = Outlet::query()->create([
            'code' => strtolower($suffix),
            'name' => 'Dispatch Outlet '.$suffix,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'receipt-'.$suffix,
            'name' => 'Receipt',
            'station' => 'cashier',
            'connection_type' => 'lan',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);
        $route = PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'printer_profile_id' => (int) $profile->id,
            'print_type' => 'receipt',
            'priority' => 1,
            'is_active' => true,
        ]);

        return [$outlet, $route];
    }

    /**
     * @return array{0:Outlet,1:PrinterRoute,2:HardwareBridgeDevice}
     */
    private function createReceiptRouteWithBridge(string $suffix): array
    {
        [$outlet, $route] = $this->createReceiptRoute($suffix);
        $device = HardwareBridgeDevice::query()->create([
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-'.$suffix,
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $profile = $route->profile;
        if ($profile !== null) {
            $profile->meta = ['bridge' => ['deviceKey' => $device->device_key], 'lan' => ['port' => 9100]];
            $profile->save();
        }

        return [$outlet, $route, $device];
    }
}
