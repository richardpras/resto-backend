<?php

namespace Tests\Feature;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Print\Services\PrintBridgeDispatchService;
use App\Modules\Print\Services\PrintBridgePayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintBridgeTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_builder_maps_usb_bluetooth_and_shared_profiles(): void
    {
        $builder = app(PrintBridgePayloadBuilder::class);

        $usbProfile = new PrinterProfile([
            'connection_type' => 'usb',
            'device_identifier' => '/dev/usb/lp0',
        ]);
        $usb = $builder->buildExecutionPayload($this->makeJob(), $usbProfile);
        $this->assertSame('usb', $usb['transport']);
        $this->assertSame('/dev/usb/lp0', $usb['devicePath']);

        $btProfile = new PrinterProfile([
            'connection_type' => 'bluetooth',
            'bluetooth_address' => '11:22:33:44:55:66',
            'meta' => ['bluetooth' => ['devicePath' => '/dev/rfcomm0']],
        ]);
        $bt = $builder->buildExecutionPayload($this->makeJob(), $btProfile);
        $this->assertSame('bluetooth', $bt['transport']);
        $this->assertSame('11:22:33:44:55:66', $bt['bluetoothAddress']);
        $this->assertSame('/dev/rfcomm0', $bt['devicePath']);

        $sharedProfile = new PrinterProfile([
            'connection_type' => 'shared',
            'name' => 'Kitchen Shared',
            'meta' => ['share' => ['path' => '\\\\SERVER\\KitchenPrinter']],
        ]);
        $shared = $builder->buildExecutionPayload($this->makeJob(), $sharedProfile);
        $this->assertSame('shared', $shared['transport']);
        $this->assertSame('\\\\SERVER\\KitchenPrinter', $shared['sharePath']);
    }

    public function test_dispatch_rejects_disabled_usb_transport(): void
    {
        config(['print.transport.usb.enabled' => false]);

        $outlet = Outlet::query()->create([
            'code' => 'pbt-usb',
            'name' => 'USB Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        HardwareBridgeDevice::query()->create([
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-usb',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'usb-printer',
            'name' => 'USB Printer',
            'connection_type' => 'usb',
            'device_identifier' => '/dev/usb/lp0',
            'is_active' => true,
            'meta' => ['bridge' => ['deviceKey' => 'bridge-usb']],
        ]);
        $job = PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'type' => 'receipt',
            'printer_profile_id' => (int) $profile->id,
            'source_type' => 'order',
            'source_id' => 501,
            'idempotency_key' => 'usb-disabled',
            'dedupe_key' => sha1('usb-disabled'),
            'content' => [],
            'printable_snapshot' => ['thermalText' => "Receipt\n"],
            'status' => 'pending',
            'attempts' => 1,
            'queued_at' => now(),
            'max_attempts' => 5,
            'retryable' => true,
            'recovery_state' => 'none',
        ]);

        $this->expectExceptionMessage('Print transport [usb] is disabled.');

        app(PrintBridgeDispatchService::class)->dispatch($job);
    }

    public function test_dispatch_enqueues_shared_transport_when_enabled(): void
    {
        config(['print.transport.shared.enabled' => true]);

        $outlet = Outlet::query()->create([
            'code' => 'pbt-shared',
            'name' => 'Shared Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        HardwareBridgeDevice::query()->create([
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-shared',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'shared-printer',
            'name' => 'Kitchen Shared',
            'connection_type' => 'shared',
            'is_active' => true,
            'meta' => [
                'bridge' => ['deviceKey' => 'bridge-shared'],
                'share' => ['path' => '\\\\SERVER\\KitchenPrinter'],
            ],
        ]);
        $job = PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'type' => 'kitchen',
            'printer_profile_id' => (int) $profile->id,
            'source_type' => 'order',
            'source_id' => 502,
            'idempotency_key' => 'shared-enabled',
            'dedupe_key' => sha1('shared-enabled'),
            'content' => [],
            'printable_snapshot' => ['thermalText' => "Kitchen\n"],
            'status' => 'pending',
            'attempts' => 1,
            'queued_at' => now(),
            'max_attempts' => 5,
            'retryable' => true,
            'recovery_state' => 'none',
        ]);

        $command = app(PrintBridgeDispatchService::class)->dispatch($job);

        $payload = is_array($command->payload) ? $command->payload : [];
        $this->assertSame('shared', $payload['transport'] ?? null);
        $this->assertSame('\\\\SERVER\\KitchenPrinter', $payload['sharePath'] ?? null);
    }

    private function makeJob(): PrintJob
    {
        return new PrintJob([
            'type' => 'receipt',
            'source_type' => 'order',
            'source_id' => 1,
            'printable_snapshot' => ['thermalText' => "Receipt\nTotal: 10000"],
        ]);
    }
}
