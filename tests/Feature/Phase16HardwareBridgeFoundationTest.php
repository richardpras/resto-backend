<?php

namespace Tests\Feature;

use App\Events\Hardware\BridgeConnected;
use App\Events\Hardware\BridgeDisconnected;
use App\Events\Hardware\BridgeHeartbeat;
use App\Events\Hardware\BridgeLifecycleConnected;
use App\Events\Hardware\BridgeLifecycleDisconnected;
use App\Events\Hardware\CommandAcknowledged;
use App\Events\Hardware\CommandReceived;
use App\Events\Hardware\HardwareCommandAcknowledged;
use App\Events\Hardware\QueueDegraded;
use App\Events\Hardware\SpoolRecovered;
use App\Models\Modules\Hardware\Domain\HardwareCommandLog;
use App\Models\Modules\Hardware\Domain\PrinterDeviceProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Loyalty\Services\CustomerAnalyticsService;
use App\Modules\Print\Services\PrinterManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase16HardwareBridgeFoundationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_device_registration_creates_hardware_bridge_device(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-REG');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $response = $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'bridge-device-'.$outlet->id,
            'displayLabel' => 'Backoffice Bridge',
            'capabilities' => ['print' => true, 'cashDrawer' => true],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.outletId', (int) $outlet->id)
            ->assertJsonPath('data.deviceKey', 'bridge-device-'.$outlet->id)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('hardware_bridge_devices', [
            'outlet_id' => (int) $outlet->id,
            'device_key' => 'bridge-device-'.$outlet->id,
            'status' => 'active',
        ]);
    }

    public function test_heartbeat_and_session_lifecycle_track_reconnects(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-SES');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'session-device-'.$outlet->id,
        ])->assertOk();

        $open = $this->postJson('/api/v1/hardware/sessions/open', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'session-device-'.$outlet->id,
            'metadata' => ['appVersion' => '16.0.0'],
        ])->assertOk();

        $sessionId = (int) $open->json('data.id');
        $this->assertGreaterThan(0, $sessionId);

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'session-device-'.$outlet->id,
            'sessionId' => $sessionId,
            'status' => 'online',
        ])->assertOk()->assertJsonPath('data.reconnectCount', 0);

        DB::table('hardware_bridge_devices')
            ->where('outlet_id', (int) $outlet->id)
            ->where('device_key', 'session-device-'.$outlet->id)
            ->update(['last_seen_at' => now()->subHours(2)]);

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'session-device-'.$outlet->id,
            'sessionId' => $sessionId,
            'status' => 'online',
        ])->assertOk()->assertJsonPath('data.reconnectCount', 1);

        $this->postJson('/api/v1/hardware/sessions/'.$sessionId.'/close', [
            'reason' => 'manual-close',
        ])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_duplicate_command_suppression_and_ack_idempotency_are_safe(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-CMD');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'command-device-'.$outlet->id,
        ])->assertOk();

        $first = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'command-device-'.$outlet->id,
            'commandType' => 'PRINT_DOCUMENT',
            'idempotencyKey' => 'cmd-dup-'.$outlet->id,
            'payload' => ['documentType' => 'receipt', 'documentId' => 101],
        ])->assertOk();
        $commandId = (int) $first->json('data.id');

        $duplicate = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'command-device-'.$outlet->id,
            'commandType' => 'PRINT_DOCUMENT',
            'idempotencyKey' => 'cmd-dup-'.$outlet->id,
            'payload' => ['documentType' => 'receipt', 'documentId' => 101],
        ]);
        $duplicate->assertOk()
            ->assertJsonPath('data.id', $commandId)
            ->assertJsonPath('data.deduplicated', true);

        $this->postJson('/api/v1/hardware/commands/'.$commandId.'/ack', [
            'ackPayload' => ['result' => 'printed'],
        ])->assertOk()->assertJsonPath('data.status', 'acknowledged');

        $this->postJson('/api/v1/hardware/commands/'.$commandId.'/ack', [
            'ackPayload' => ['result' => 'printed'],
        ])->assertOk()->assertJsonPath('data.status', 'acknowledged');
    }

    public function test_hardware_endpoints_are_outlet_isolated(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = $this->createOutlet('HB-ALLOW');
        $blockedOutlet = $this->createOutlet('HB-BLOCK');
        $this->assignUserToOutlets($user, [(int) $allowedOutlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $blockedOutlet->id,
            'deviceKey' => 'blocked-device-'.$blockedOutlet->id,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $allowedOutlet->id,
            'deviceKey' => 'allowed-device-'.$allowedOutlet->id,
        ])->assertOk();

        $this->getJson('/api/v1/hardware/devices?outletId='.(int) $allowedOutlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.deviceKey', 'allowed-device-'.$allowedOutlet->id);

        $this->getJson('/api/v1/hardware/devices?outletId='.(int) $blockedOutlet->id)
            ->assertUnprocessable();
    }

    public function test_hardware_lifecycle_emits_realtime_events(): void
    {
        Event::fake([
            BridgeConnected::class,
            BridgeDisconnected::class,
            BridgeHeartbeat::class,
            BridgeLifecycleConnected::class,
            BridgeLifecycleDisconnected::class,
            CommandReceived::class,
            CommandAcknowledged::class,
            QueueDegraded::class,
            SpoolRecovered::class,
            HardwareCommandAcknowledged::class,
        ]);

        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-EVT');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'event-device-'.$outlet->id,
        ])->assertOk();

        $session = $this->postJson('/api/v1/hardware/sessions/open', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'event-device-'.$outlet->id,
        ])->assertOk();
        $sessionId = (int) $session->json('data.id');

        $command = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'event-device-'.$outlet->id,
            'commandType' => 'TEST_PRINT',
            'idempotencyKey' => 'evt-cmd-'.$outlet->id,
            'payload' => ['paperWidth' => 58],
        ])->assertOk();
        $commandId = (int) $command->json('data.id');

        $this->postJson('/api/v1/hardware/commands/'.$commandId.'/ack', [
            'ackPayload' => ['result' => 'ok'],
        ])->assertOk();

        $this->postJson('/api/v1/hardware/sessions/'.$sessionId.'/close', [
            'reason' => 'disconnect',
        ])->assertOk();

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'event-device-'.$outlet->id,
            'sessionId' => $sessionId,
            'runtimeState' => 'degraded',
            'queueDepth' => 999,
            'spoolSupported' => true,
            'transports' => ['websocket'],
            'capabilities' => ['print' => true],
            'reconnectMetadata' => ['strategy' => 'exponential'],
        ])->assertOk();

        Event::assertDispatched(BridgeConnected::class);
        Event::assertDispatched(BridgeLifecycleConnected::class);
        Event::assertDispatched(HardwareCommandAcknowledged::class);
        Event::assertDispatched(CommandReceived::class);
        Event::assertDispatched(CommandAcknowledged::class);
        Event::assertDispatched(BridgeDisconnected::class);
        Event::assertDispatched(BridgeLifecycleDisconnected::class);
        Event::assertDispatched(BridgeHeartbeat::class);
        Event::assertDispatched(QueueDegraded::class);
    }

    public function test_reconnect_safe_runtime_and_recovery_metadata_are_persisted(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-REC');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'reconnect-device-'.$outlet->id,
        ])->assertOk();

        $session = $this->postJson('/api/v1/hardware/sessions/open', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'reconnect-device-'.$outlet->id,
            'runtimeState' => 'recovering',
            'spoolSupported' => true,
            'queueDepth' => 2,
            'transports' => ['ws', 'http-poll'],
            'capabilities' => ['receiptPrint' => true],
            'reconnectMetadata' => ['attempt' => 3, 'backoffMs' => 4000],
        ])->assertOk();

        $sessionId = (int) $session->json('data.id');

        $heartbeat = $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'reconnect-device-'.$outlet->id,
            'sessionId' => $sessionId,
            'runtimeState' => 'reconnecting',
            'spoolSupported' => true,
            'queueDepth' => 5,
            'transports' => ['ws'],
            'capabilities' => ['cashDrawer' => true],
            'reconnectMetadata' => ['attempt' => 4, 'backoffMs' => 8000],
        ])->assertOk();

        $heartbeat->assertJsonPath('data.runtimeContract.state', 'reconnecting');
        $this->assertArrayHasKey('resumeMarker', (array) $heartbeat->json('data.runtimeContract'));
    }

    public function test_stale_bridge_detection_marks_runtime_state_stale(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-STL');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'stale-device-'.$outlet->id,
        ])->assertOk();

        DB::table('hardware_bridge_devices')
            ->where('outlet_id', (int) $outlet->id)
            ->where('device_key', 'stale-device-'.$outlet->id)
            ->update(['last_seen_at' => now()->subHours(5)]);

        $response = $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'stale-device-'.$outlet->id,
            'runtimeState' => 'connected',
        ])->assertOk();

        $response->assertJsonPath('data.runtimeContract.state', 'stale');
    }

    public function test_nack_retry_lifecycle_moves_to_dead_letter_with_backoff(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-NCK');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'nack-device-'.$outlet->id,
        ])->assertOk();

        $command = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'nack-device-'.$outlet->id,
            'commandType' => 'PRINT_DOCUMENT',
            'idempotencyKey' => 'nack-cmd-'.$outlet->id,
            'maxRetries' => 1,
        ])->assertOk();
        $commandId = (int) $command->json('data.id');

        $first = $this->postJson('/api/v1/hardware/commands/'.$commandId.'/nack', [
            'errorCode' => 'PRINTER_OFFLINE',
        ])->assertOk();
        $first->assertJsonPath('data.spoolStatus', 'replay_pending');

        $second = $this->postJson('/api/v1/hardware/commands/'.$commandId.'/nack', [
            'errorCode' => 'PRINTER_OFFLINE',
        ])->assertOk();
        $second->assertJsonPath('data.spoolStatus', 'dead_letter');

        $this->assertDatabaseHas('hardware_command_logs', [
            'id' => $commandId,
            'status' => 'dead_letter',
        ]);
    }

    public function test_bridge_can_pull_pending_commands_with_resume_marker_and_outlet_scope(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-PULL');
        $otherOutlet = $this->createOutlet('HB-PULL-OTHER');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'pull-device-'.$outlet->id,
        ])->assertOk();

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $otherOutlet->id,
            'deviceKey' => 'pull-device-'.$otherOutlet->id,
        ])->assertUnprocessable();

        $commandA = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'pull-device-'.$outlet->id,
            'commandType' => 'PRINT_DOCUMENT',
            'idempotencyKey' => 'pull-a-'.$outlet->id,
        ])->assertOk();
        $commandB = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'pull-device-'.$outlet->id,
            'commandType' => 'OPEN_CASH_DRAWER',
            'idempotencyKey' => 'pull-b-'.$outlet->id,
        ])->assertOk();
        $ackId = (int) $commandA->json('data.id');
        $this->postJson('/api/v1/hardware/commands/'.$ackId.'/ack', [
            'ackPayload' => ['result' => 'ok'],
        ])->assertOk();

        $commandC = $this->postJson('/api/v1/hardware/commands/enqueue', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'pull-device-'.$outlet->id,
            'commandType' => 'TEST_PRINT',
            'idempotencyKey' => 'pull-c-'.$outlet->id,
        ])->assertOk();

        $resumeAfter = (int) $commandB->json('data.id');
        $pull = $this->getJson('/api/v1/hardware/commands/pull?outletId='.(int) $outlet->id.'&deviceKey=pull-device-'.$outlet->id.'&afterCommandId='.$resumeAfter)
            ->assertOk();
        $items = $pull->json('data.commands');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertSame((int) $commandC->json('data.id'), (int) ($items[0]['id'] ?? 0));
        $pull->assertJsonPath('data.resumeMarker', $ackId);
        $pull->assertJsonPath('data.hasMore', false);
    }

    public function test_monitoring_metrics_include_hardware_bridge_observability_contracts(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-MON');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->mock(CustomerAnalyticsService::class, function ($mock): void {
            $mock->shouldReceive('metricsForOutlets')->andReturn([]);
        });

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'monitor-device-'.$outlet->id,
        ])->assertOk();

        $command = HardwareCommandLog::query()->create([
            'outlet_id' => (int) $outlet->id,
            'hardware_bridge_device_id' => (int) DB::table('hardware_bridge_devices')->where('outlet_id', (int) $outlet->id)->value('id'),
            'command_type' => 'PING',
            'status' => 'acknowledged',
            'idempotency_key' => 'monitor-ack-'.$outlet->id,
            'retry_count' => 2,
            'max_retries' => 3,
            'created_at' => now()->subSeconds(5),
            'acked_at' => now(),
        ]);
        $this->assertNotNull($command->id);

        HardwareCommandLog::query()->create([
            'outlet_id' => (int) $outlet->id,
            'hardware_bridge_device_id' => (int) DB::table('hardware_bridge_devices')->where('outlet_id', (int) $outlet->id)->value('id'),
            'command_type' => 'PING',
            'status' => 'dead_letter',
            'idempotency_key' => 'monitor-dlq-'.$outlet->id,
            'retry_count' => 4,
            'max_retries' => 3,
        ]);

        $response = $this->getJson('/api/v1/monitoring/metrics?outletId='.(int) $outlet->id)->assertOk();
        $response->assertJsonPath('data.hardwareBridge.activeBridges', 1);
        $response->assertJsonPath('data.hardwareBridge.deadLetterCount', 1);
        $response->assertJsonPath('data.hardwareBridge.retryCount', 6);
    }

    public function test_connection_type_and_capability_metadata_are_persisted_for_print_and_hardware_profiles(): void
    {
        $outlet = $this->createOutlet('HB-META');
        /** @var PrinterManagementService $service */
        $service = app(PrinterManagementService::class);
        $profile = $service->createProfile([
            'outletId' => (int) $outlet->id,
            'code' => 'meta-printer',
            'name' => 'Meta Printer',
            'connectionType' => 'usb',
            'deviceIdentifier' => 'USB:VID_1A2B',
            'ipAddress' => '192.168.10.20',
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'bluetoothName' => 'BT Printer',
            'bluetoothAddress' => '11:22:33:44:55:66',
            'meta' => [
                'pairingState' => 'paired',
                'lastConnectedAt' => now()->toIso8601String(),
                'reconnect' => ['count' => 2, 'lastReason' => 'signal_drop'],
                'signal' => ['rssi' => -52, 'status' => 'stable'],
            ],
        ]);

        $this->assertDatabaseHas('printer_profiles', [
            'id' => (int) $profile->id,
            'connection_type' => 'usb',
            'device_identifier' => 'USB:VID_1A2B',
            'ip_address' => '192.168.10.20',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'bluetooth_name' => 'BT Printer',
            'bluetooth_address' => '11:22:33:44:55:66',
        ]);

        $hardwareProfile = PrinterDeviceProfile::query()->create([
            'outlet_id' => (int) $outlet->id,
            'printer_code' => 'bridge-meta',
            'name' => 'Bridge Meta Printer',
            'connection_type' => 'bluetooth',
            'status' => 'online',
            'device_identifier' => 'bridge-meta-device',
            'ip_address' => null,
            'mac_address' => '00:11:22:33:44:55',
            'bluetooth_name' => 'Bridge BT Printer',
            'bluetooth_address' => '66:55:44:33:22:11',
            'metadata' => [
                'pairingState' => 'paired',
                'lastConnectedAt' => now()->toIso8601String(),
                'reconnect' => ['count' => 1],
                'signal' => ['rssi' => -61, 'status' => 'healthy'],
            ],
        ]);

        $this->assertDatabaseHas('printer_device_profiles', [
            'id' => (int) $hardwareProfile->id,
            'connection_type' => 'bluetooth',
            'device_identifier' => 'bridge-meta-device',
            'mac_address' => '00:11:22:33:44:55',
            'bluetooth_name' => 'Bridge BT Printer',
            'bluetooth_address' => '66:55:44:33:22:11',
        ]);
    }

    public function test_provisioning_watchdog_update_and_deployment_runtime_metadata_are_persisted(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-PROD');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'prod-device-'.$outlet->id,
            'metadata' => [
                'provisioning' => [
                    'pairingTokenRef' => 'pair-ref-001',
                    'pairedAt' => now()->toIso8601String(),
                    'pairedOutletId' => (int) $outlet->id,
                ],
                'auth' => [
                    'deviceFingerprint' => 'fp-abc-123',
                    'tokenHealth' => ['status' => 'healthy', 'expiresInSeconds' => 3600],
                    'rotation' => ['lastRotatedAt' => now()->subHour()->toIso8601String(), 'rotationDue' => false],
                ],
            ],
        ])->assertOk();

        $session = $this->postJson('/api/v1/hardware/sessions/open', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'prod-device-'.$outlet->id,
            'runtimeState' => 'recovering',
            'metadata' => [
                'runtimeVersion' => '16.3.0',
                'deployment' => [
                    'serviceMode' => 'windows-service',
                    'headless' => true,
                    'trayVisible' => false,
                ],
                'updates' => [
                    'channel' => 'stable',
                    'available' => true,
                    'targetVersion' => '16.3.1',
                    'deferred' => true,
                    'restartRequired' => true,
                ],
            ],
        ])->assertOk();
        $sessionId = (int) $session->json('data.id');

        $heartbeat = $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'prod-device-'.$outlet->id,
            'sessionId' => $sessionId,
            'runtimeState' => 'degraded',
            'queueDepth' => 12,
            'metadata' => [
                'watchdog' => [
                    'state' => 'degraded',
                    'stalledSpoolDetected' => true,
                    'freezeDetected' => true,
                    'restartCount' => 2,
                    'crashCount' => 1,
                ],
                'spoolHealth' => [
                    'stalled' => true,
                    'oldestPendingAgeSeconds' => 95,
                ],
                'updates' => [
                    'lastFailedVersion' => '16.3.1',
                    'lastFailureAt' => now()->subMinutes(3)->toIso8601String(),
                    'recoveryState' => 'rolled_back',
                ],
            ],
        ])->assertOk();

        $heartbeat->assertJsonPath('data.metadata.watchdog.state', 'degraded');
        $heartbeat->assertJsonPath('data.metadata.auth.deviceFingerprint', 'fp-abc-123');
        $heartbeat->assertJsonPath('data.metadata.provisioning.pairingTokenRef', 'pair-ref-001');
        $heartbeat->assertJsonPath('data.metadata.updates.restartRequired', true);
        $heartbeat->assertJsonPath('data.metadata.deployment.serviceMode', 'windows-service');
    }

    public function test_device_revoke_and_disable_paths_block_subsequent_runtime_actions(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('HB-REVOKE');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $register = $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'revokable-device-'.$outlet->id,
        ])->assertOk();
        $deviceId = (int) $register->json('data.id');

        $this->postJson('/api/v1/hardware/devices/'.$deviceId.'/disable', [
            'reason' => 'maintenance-lock',
        ])->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->postJson('/api/v1/hardware/devices/heartbeat', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'revokable-device-'.$outlet->id,
            'runtimeState' => 'connected',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/hardware/devices/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'revokable-device-'.$outlet->id,
        ])->assertOk();

        $this->postJson('/api/v1/hardware/devices/'.$deviceId.'/revoke', [
            'reason' => 'token-compromise',
        ])->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->postJson('/api/v1/hardware/sessions/open', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'revokable-device-'.$outlet->id,
        ])->assertUnprocessable();
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
