<?php

namespace Tests\Feature;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Modules\Hardware\Support\HardwareCommandType;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class PrinterSettingsTestPrintTest extends TestCase
{
    use RefreshDatabase;
    use ProductionStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['print.transport.shared.enabled' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_settings_printer_test_dispatches_hardware_command_for_shared_printer(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Test Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pt-'.uniqid('', true),
        ]);
        HardwareBridgeDevice::query()->create([
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-test',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'kasir-test',
            'name' => 'Kasir Test',
            'station' => 'cashier',
            'connection_type' => 'shared',
            'is_active' => true,
            'meta' => [
                'bridge' => ['deviceKey' => 'bridge-test'],
                'share' => [
                    'path' => '\\\\PC\\Kasir-Printer',
                    'printerName' => 'Kasir',
                ],
            ],
        ]);
        SettingPrinter::query()->create([
            'id' => 'kasir-test-printer',
            'name' => 'Kasir Test',
            'printer_type' => 'cashier',
            'connection' => 'shared',
            'ip' => 'Kasir',
            'bluetooth_device' => '\\\\PC\\Kasir-Printer',
            'outlet_id' => (int) $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => (int) $profile->id,
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/printers/kasir-test-printer/test');

        $response->assertOk()
            ->assertJsonPath('data.recoveryState', 'awaiting_ack')
            ->assertJsonPath('data.hardwareCommandLogId', fn ($value) => is_int($value) && $value > 0);

        $jobId = (int) $response->json('data.printJobId');
        $commandId = (int) $response->json('data.hardwareCommandLogId');

        $this->assertDatabaseHas('print_jobs', [
            'id' => $jobId,
            'source_type' => 'printer_test',
            'recovery_state' => 'awaiting_ack',
            'hardware_command_log_id' => $commandId,
        ]);
        $this->assertDatabaseHas('hardware_command_logs', [
            'id' => $commandId,
            'command_type' => HardwareCommandType::PRINT_DOCUMENT,
            'status' => 'pending',
        ]);
    }

    public function test_settings_printer_test_rejects_when_bridge_offline(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Offline Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pt-off-'.uniqid('', true),
        ]);
        SettingPrinter::query()->create([
            'id' => 'offline-printer',
            'name' => 'Offline Printer',
            'printer_type' => 'kitchen',
            'connection' => 'lan',
            'ip' => '10.0.0.10',
            'outlet_id' => (int) $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => null,
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $this->postJson('/api/v1/printers/offline-printer/test')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hardware bridge is offline for this outlet.');
    }

    public function test_settings_printer_test_rejects_disabled_shared_transport(): void
    {
        config(['print.transport.shared.enabled' => false]);

        $outlet = Outlet::query()->create([
            'name' => 'Shared Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pt-sh-'.uniqid('', true),
        ]);
        HardwareBridgeDevice::query()->create([
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-shared-off',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'shared-off',
            'name' => 'Shared Printer',
            'station' => 'kitchen',
            'connection_type' => 'shared',
            'is_active' => true,
            'meta' => [
                'bridge' => ['deviceKey' => 'bridge-shared-off'],
                'share' => ['path' => '\\\\PC\\Kitchen'],
            ],
        ]);
        SettingPrinter::query()->create([
            'id' => 'shared-off-printer',
            'name' => 'Shared Printer',
            'printer_type' => 'kitchen',
            'connection' => 'shared',
            'ip' => 'Kitchen',
            'bluetooth_device' => '\\\\PC\\Kitchen',
            'outlet_id' => (int) $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => (int) $profile->id,
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $this->postJson('/api/v1/printers/shared-off-printer/test')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Print transport [shared] is disabled.');

        $this->assertSame(0, PrintJob::query()->count());
    }
}
