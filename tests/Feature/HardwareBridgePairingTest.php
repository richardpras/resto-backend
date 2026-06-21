<?php

namespace Tests\Feature;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class HardwareBridgePairingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_init_pairing_requires_settings_permission(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('PAIR-INIT');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $response = $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
            'displayLabel' => 'Komputer Kasir',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['code', 'expiresAt', 'outletId']]);

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $response->json('data.code'));
    }

    public function test_redeem_pairing_issues_device_credentials(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('PAIR-REDEEM');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $init = $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
            'displayLabel' => 'Komputer Printer',
        ])->assertOk();

        $code = (string) $init->json('data.code');

        $redeem = $this->postJson('/api/v1/hardware/pairing/redeem', [
            'code' => $code,
            'deviceKey' => 'bridge-pair-'.$outlet->id,
            'displayLabel' => 'PC Printer Outlet',
            'fingerprint' => 'fp-test-001',
            'capabilities' => ['polling' => true],
        ]);

        $redeem->assertOk()
            ->assertJsonPath('data.outletId', (int) $outlet->id)
            ->assertJsonPath('data.deviceKey', 'bridge-pair-'.$outlet->id)
            ->assertJsonStructure(['data' => ['accessToken', 'refreshToken', 'expiresAt', 'refreshExpiresAt']]);

        $this->assertDatabaseHas('hardware_bridge_devices', [
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-pair-'.$outlet->id,
            'status' => 'active',
        ]);

        $accessToken = (string) $redeem->json('data.accessToken');

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'bridge-pair-'.$outlet->id,
            'status' => 'online',
        ], [
            'Authorization' => 'Bearer '.$accessToken,
        ])->assertOk();
    }

    public function test_expired_pairing_code_is_rejected(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('PAIR-EXP');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $init = $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
        ])->assertOk();

        $code = (string) $init->json('data.code');

        \App\Models\Modules\Hardware\Domain\HardwarePairingCode::query()
            ->where('outlet_id', (int) $outlet->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/hardware/pairing/redeem', [
            'code' => $code,
            'deviceKey' => 'bridge-exp-'.$outlet->id,
        ])->assertStatus(422);
    }

    public function test_refresh_token_rotates_access_token(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('PAIR-REF');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $code = (string) $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
        ])->json('data.code');

        $redeem = $this->postJson('/api/v1/hardware/pairing/redeem', [
            'code' => $code,
            'deviceKey' => 'bridge-ref-'.$outlet->id,
        ])->assertOk();

        $refreshToken = (string) $redeem->json('data.refreshToken');
        $oldAccess = (string) $redeem->json('data.accessToken');

        $refresh = $this->postJson('/api/v1/hardware/auth/refresh', [
            'refreshToken' => $refreshToken,
        ])->assertOk();

        $newAccess = (string) $refresh->json('data.accessToken');
        $this->assertNotSame($oldAccess, $newAccess);

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'bridge-ref-'.$outlet->id,
        ], [
            'Authorization' => 'Bearer '.$newAccess,
        ])->assertOk();
    }

    public function test_revoke_device_invalidates_credentials(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('PAIR-REV');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $code = (string) $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
        ])->json('data.code');

        $redeem = $this->postJson('/api/v1/hardware/pairing/redeem', [
            'code' => $code,
            'deviceKey' => 'bridge-rev-'.$outlet->id,
        ])->assertOk();

        $accessToken = (string) $redeem->json('data.accessToken');
        $deviceId = (int) $redeem->json('data.deviceId');

        $this->postJson('/api/v1/hardware/devices/'.$deviceId.'/revoke', [
            'reason' => 'ganti-pc',
        ])->assertOk();

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'bridge-rev-'.$outlet->id,
        ], [
            'Authorization' => 'Bearer '.$accessToken,
        ])->assertUnauthorized();
    }

    public function test_settings_user_can_list_devices_after_pairing_without_pos_use(): void
    {
        $this->seedUserManagementGatePermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_settings_pairing__'],
            ['description' => 'Settings-only pairing operator'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['settings.view', 'settings.update'])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'settings-pairing-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $outlet = $this->createOutlet('PAIR-SETTINGS');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        Passport::actingAs($user);

        $init = $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
        ])->assertOk();

        $this->postJson('/api/v1/hardware/pairing/redeem', [
            'code' => (string) $init->json('data.code'),
            'deviceKey' => 'bridge-settings-'.$outlet->id,
            'displayLabel' => 'PC Printer',
            'capabilities' => ['polling' => true],
        ])->assertOk();

        $this->getJson('/api/v1/hardware/devices/summary?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.deviceKey', 'bridge-settings-'.$outlet->id)
            ->assertJsonPath('data.0.provisioning.status', 'paired');
    }

    public function test_settings_user_can_read_queue_status_after_pairing_without_pos_use(): void
    {
        $this->seedUserManagementGatePermissions();

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_settings_print_status__'],
            ['description' => 'Settings-only print status reader'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['settings.view', 'settings.update'])->pluck('id')->all(),
        );

        $user = User::factory()->create([
            'email' => 'settings-print-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $outlet = $this->createOutlet('PAIR-PRINT');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        Passport::actingAs($user);

        $init = $this->postJson('/api/v1/hardware/pairing/init', [
            'outletId' => (int) $outlet->id,
        ])->assertOk();

        $this->postJson('/api/v1/hardware/pairing/redeem', [
            'code' => (string) $init->json('data.code'),
            'deviceKey' => 'bridge-print-'.$outlet->id,
            'displayLabel' => 'PC Printer',
            'capabilities' => ['polling' => true],
        ])->assertOk();

        $this->getJson('/api/v1/print/queue/status?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.bridgeConnected', true);
    }

    public function test_device_registration_completes_quickly_with_sync_queue(): void
    {
        config([
            'queue.default' => 'sync',
            'broadcasting.default' => 'log',
        ]);

        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('PAIR-SPEED');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $started = microtime(true);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'bridge-speed-'.$outlet->id,
        ])->assertOk();

        $elapsed = microtime(true) - $started;
        $this->assertLessThan(2.0, $elapsed);

        $this->assertInstanceOf(
            HardwareBridgeDevice::class,
            HardwareBridgeDevice::query()->where('device_key', 'bridge-speed-'.$outlet->id)->first(),
        );
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
}
