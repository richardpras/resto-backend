<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class HardwareBridgeDeviceSummaryTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_device_summary_returns_flattened_fields_without_full_metadata_blob(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-SUM');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'summary-device',
            'displayLabel' => 'Front Bridge',
            'capabilities' => ['print' => true, 'lan' => true],
            'metadata' => [
                'transportHints' => ['lan'],
                'runtimeVersion' => '16.3.0',
                'provisioning' => ['status' => 'paired', 'pairedAt' => now()->toIso8601String(), 'pairedOutletId' => (string) $outlet->id],
                'auth' => ['deviceFingerprint' => 'fp-123', 'tokenHealth' => ['status' => 'healthy'], 'rotation' => ['rotationDue' => false]],
                'watchdog' => ['state' => 'healthy', 'restartCount' => 1, 'crashCount' => 0],
                'deployment' => ['deploymentMode' => 'headless', 'serviceMode' => 'windows-service'],
                'updates' => ['channel' => 'stable', 'available' => false],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/v1/hardware/devices/summary?outletId='.(int) $outlet->id);

        $response->assertOk()
            ->assertJsonPath('data.0.deviceKey', 'summary-device')
            ->assertJsonPath('data.0.connectionHint', 'lan')
            ->assertJsonPath('data.0.provisioning.status', 'paired')
            ->assertJsonPath('data.0.runtime.version', '16.3.0')
            ->assertJsonPath('data.0.capabilitiesSummary.spoolSupported', false)
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.capabilities');
    }

    public function test_device_summary_is_smaller_than_full_device_list_payload(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-SIZE');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $heavyMetadata = [
            'transportHints' => ['lan', 'bluetooth'],
            'runtimeVersion' => '16.3.0',
            'provisioning' => ['status' => 'paired', 'pairedAt' => now()->toIso8601String()],
            'auth' => ['deviceFingerprint' => 'fp-heavy', 'tokenHealth' => ['status' => 'healthy']],
            'watchdog' => ['state' => 'healthy', 'restartCount' => 2, 'crashCount' => 1],
            'deployment' => ['deploymentMode' => 'headless', 'serviceMode' => 'windows-service', 'trayMode' => 'hidden'],
            'updates' => ['channel' => 'stable', 'available' => true, 'targetVersion' => '16.3.1'],
            'reconnectMetadata' => ['attempts' => range(1, 20)],
            'spoolContract' => ['statuses' => ['pending', 'processing', 'acknowledged', 'failed']],
            'noise' => str_repeat('x', 4000),
        ];

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'heavy-device',
            'capabilities' => ['print' => true, 'lan' => true, 'bluetooth' => true, 'spool' => ['supported' => true]],
            'metadata' => $heavyMetadata,
        ])->assertOk();

        DB::table('hardware_bridge_devices')
            ->where('device_key', 'heavy-device')
            ->update(['metadata' => json_encode($heavyMetadata)]);

        $summary = $this->getJson('/api/v1/hardware/devices/summary?outletId='.(int) $outlet->id)->assertOk();
        $full = $this->getJson('/api/v1/hardware/devices?outletId='.(int) $outlet->id)->assertOk();

        $summaryBytes = strlen(json_encode($summary->json('data')));
        $fullBytes = strlen(json_encode($full->json('data')));

        $this->assertLessThan($fullBytes, $summaryBytes);
        $this->assertLessThan(2500, $summaryBytes);
    }

    public function test_device_summary_enforces_outlet_access(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowed = $this->createOutlet('HB-SUM-OK');
        $blocked = $this->createOutlet('HB-SUM-NO');
        $this->assignUserToOutlets($user, [(int) $allowed->id]);

        $this->getJson('/api/v1/hardware/devices/summary?outletId='.(int) $allowed->id)->assertOk();
        $this->getJson('/api/v1/hardware/devices/summary?outletId='.(int) $blocked->id)->assertUnprocessable();
    }

    private function createOutlet(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet',
            'code' => strtolower($prefix).'-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
