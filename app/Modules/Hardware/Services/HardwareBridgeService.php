<?php

namespace App\Modules\Hardware\Services;

use App\Events\Hardware\BluetoothPrinterPaired;
use App\Events\Hardware\BridgeHeartbeat;
use App\Events\Hardware\BridgeConnected;
use App\Events\Hardware\BridgeDisconnected;
use App\Events\Hardware\BridgeLifecycleConnected;
use App\Events\Hardware\BridgeLifecycleDisconnected;
use App\Events\Hardware\CommandAcknowledged;
use App\Events\Hardware\CommandReceived;
use App\Events\Hardware\HardwareCommandAcknowledged;
use App\Events\Hardware\PrinterOfflineDetected;
use App\Events\Hardware\PrinterRecovered;
use App\Events\Hardware\QueueDegraded;
use App\Events\Hardware\SpoolRecovered;
use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Hardware\Domain\HardwareCommandLog;
use App\Models\Modules\Hardware\Domain\HardwareDeviceEvent;
use App\Models\Modules\Hardware\Domain\HardwareDeviceSession;
use App\Models\Modules\Hardware\Domain\PrinterDeviceProfile;
use App\Models\User;
use App\Modules\Hardware\Support\HardwareBridgeAuthContext;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use App\Support\HardwareRuntimeContract;

class HardwareBridgeService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly HardwareDeviceAuthService $deviceAuthService,
    ) {}

    /**
     * @param  array<string,mixed>|null  $capabilities
     * @param  array<string,mixed>  $metadata
     */
    public function registerDeviceInternal(
        int $outletId,
        string $deviceKey,
        ?string $displayLabel,
        ?array $capabilities,
        array $metadata = [],
    ): HardwareBridgeDevice {
        $device = HardwareBridgeDevice::query()->updateOrCreate(
            [
                'outlet_id' => $outletId,
                'device_key' => trim($deviceKey),
            ],
            [
                'display_label' => $displayLabel !== null ? trim($displayLabel) : null,
                'capabilities' => $capabilities,
                'metadata' => $this->mergeOperationalMetadata([], $metadata),
                'status' => 'active',
                'disabled_at' => null,
                'revoked_at' => null,
                'last_seen_at' => now(),
            ]
        );

        event(new BridgeConnected($outletId, (int) $device->id, (string) $device->device_key));
        event(new BridgeLifecycleConnected($outletId, (int) $device->id, (string) $device->device_key));
        if ((bool) data_get($metadata, 'bluetoothPaired', false)) {
            event(new BluetoothPrinterPaired($outletId, (int) $device->id, (string) $device->device_key));
        }

        $this->recordDeviceEvent($outletId, (int) $device->id, null, 'bridge_registered', ['deviceKey' => $device->device_key]);

        return $device->fresh()
            ?? throw (new ModelNotFoundException)->setModel(HardwareBridgeDevice::class, [(string) $device->id]);
    }

    /** @param array<string,mixed> $data */
    public function registerDevice(User $user, array $data): HardwareBridgeDevice
    {
        return $this->registerDeviceForContext(HardwareBridgeAuthContext::fromUser($user), $data);
    }

    /** @param array<string,mixed> $data */
    public function registerDeviceForContext(HardwareBridgeAuthContext $context, array $data): HardwareBridgeDevice
    {
        $outletId = (int) $data['outletId'];
        $context->assertOutletAllowed($outletId, $this->outletAccessResolver);
        $deviceKey = trim((string) $data['deviceKey']);
        if ($context->isDeviceAuth()) {
            if ((int) $context->device->outlet_id !== $outletId || (string) $context->device->device_key !== $deviceKey) {
                throw ValidationException::withMessages([
                    'deviceKey' => ['The authenticated bridge device cannot register for another identity.'],
                ]);
            }
        }

        return $this->registerDeviceInternal(
            $outletId,
            $deviceKey,
            isset($data['displayLabel']) ? trim((string) $data['displayLabel']) : null,
            isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : null,
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [],
        );
    }

    /** @param array<string,mixed> $data */
    public function heartbeat(User $user, array $data): HardwareBridgeDevice
    {
        return $this->heartbeatForContext(HardwareBridgeAuthContext::fromUser($user), $data);
    }

    /** @param array<string,mixed> $data */
    public function heartbeatForContext(HardwareBridgeAuthContext $context, array $data): HardwareBridgeDevice
    {
        $outletId = (int) $data['outletId'];
        $context->assertOutletAllowed($outletId, $this->outletAccessResolver);
        $device = $this->resolveUsableDevice($outletId, (string) $data['deviceKey']);
        $context->assertDeviceMatches($device);

        $now = CarbonImmutable::now();
        $gapMinutes = max(1, (int) config('hardware.reconnect_gap_minutes', 30));
        $last = $device->last_seen_at?->toImmutable();
        if ($last !== null && $last->lte($now->subMinutes($gapMinutes))) {
            $device->reconnect_count = (int) $device->reconnect_count + 1;
        }

        $runtimeState = $this->resolveRuntimeState($data, $device);
        $heartbeatContract = $this->heartbeatContract($data, $device, $runtimeState);
        $device->last_seen_at = $now;
        $device->capabilities = array_merge($device->capabilities ?? [], $heartbeatContract['capabilities']);
        $device->metadata = $this->mergeOperationalMetadata(
            array_merge($device->metadata ?? [], $heartbeatContract['metadata']),
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []
        );
        $device->save();

        $sessionId = isset($data['sessionId']) ? (int) $data['sessionId'] : null;
        if ($sessionId !== null) {
            $session = HardwareDeviceSession::query()
                ->whereKey($sessionId)
                ->where('outlet_id', $outletId)
                ->first();
            if ($session instanceof HardwareDeviceSession) {
                $session->last_heartbeat_at = $now;
                $session->metadata = array_merge($session->metadata ?? [], [
                    'runtimeContract' => [
                        'state' => $runtimeState,
                        'heartbeatAt' => $now->toIso8601String(),
                        'resumeMarker' => $this->resumeMarkerForOutlet($outletId, (int) $device->id),
                    ],
                ]);
                $session->save();
            }
        }

        $status = isset($data['status']) ? strtolower((string) $data['status']) : null;
        if (in_array($status, ['offline', 'online'], true)) {
            $profile = PrinterDeviceProfile::query()->firstOrCreate(
                ['outlet_id' => $outletId, 'printer_code' => 'default-bridge-printer'],
                ['name' => 'Default Bridge Printer', 'connection_type' => 'unknown']
            );
            $previousStatus = (string) $profile->status;
            $profile->status = $status;
            $profile->last_seen_at = $now;
            $profile->hardware_bridge_device_id = (int) $device->id;
            $profile->last_connected_at = $now;
            $profile->reconnect_metadata = [
                'count' => (int) $device->reconnect_count,
                'gapMinutes' => $gapMinutes,
            ];
            if (is_array($device->metadata)) {
                $profile->device_identifier = data_get($device->metadata, 'deviceIdentifier', $profile->device_identifier);
                $profile->ip_address = data_get($device->metadata, 'ipAddress', $profile->ip_address);
                $profile->mac_address = data_get($device->metadata, 'macAddress', $profile->mac_address);
                $profile->bluetooth_name = data_get($device->metadata, 'bluetoothName', $profile->bluetooth_name);
                $profile->bluetooth_address = data_get($device->metadata, 'bluetoothAddress', $profile->bluetooth_address);
                $profile->pairing_state = data_get($device->metadata, 'pairingState', $profile->pairing_state);
                $profile->signal_metadata = data_get($device->metadata, 'signal', $profile->signal_metadata);
            }
            $profile->save();
            if ($previousStatus !== 'offline' && $status === 'offline') {
                event(new PrinterOfflineDetected($outletId, (int) $device->id, (string) $device->device_key));
            }
            if ($previousStatus === 'offline' && $status === 'online') {
                event(new PrinterRecovered($outletId, (int) $device->id, (string) $device->device_key));
            }
        }

        $this->recordDeviceEvent($outletId, (int) $device->id, $sessionId, 'heartbeat_received', [
            'status' => $status,
            'reconnectCount' => (int) $device->reconnect_count,
            'runtimeState' => $runtimeState,
            'queueDepth' => $heartbeatContract['metadata']['queueDepth'] ?? 0,
        ]);
        event(new BridgeHeartbeat($outletId, (int) $device->id, (string) $device->device_key, $runtimeState));
        $this->emitQueueSignals($outletId, (int) $device->id, $runtimeState, $heartbeatContract['metadata']);

        return $device->fresh()
            ?? throw (new ModelNotFoundException)->setModel(HardwareBridgeDevice::class, [(string) $device->id]);
    }

    /** @param array<string,mixed> $data */
    public function openSession(User $user, array $data): HardwareDeviceSession
    {
        return $this->openSessionForContext(HardwareBridgeAuthContext::fromUser($user), $data);
    }

    /** @param array<string,mixed> $data */
    public function openSessionForContext(HardwareBridgeAuthContext $context, array $data): HardwareDeviceSession
    {
        $outletId = (int) $data['outletId'];
        $context->assertOutletAllowed($outletId, $this->outletAccessResolver);
        $device = $this->resolveUsableDevice($outletId, (string) $data['deviceKey']);
        $context->assertDeviceMatches($device);

        $runtimeState = HardwareRuntimeContract::normalizeRuntimeState(data_get($data, 'runtimeState'), 'connected');
        $session = HardwareDeviceSession::query()->create([
            'outlet_id' => $outletId,
            'hardware_bridge_device_id' => (int) $device->id,
            'session_token' => (string) Str::uuid(),
            'status' => 'open',
            'metadata' => array_merge(
                isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [],
                [
                    'runtimeContract' => [
                        'state' => $runtimeState,
                        'resumeMarker' => $this->resumeMarkerForOutlet($outletId, (int) $device->id),
                    ],
                    'negotiation' => [
                        'transports' => data_get($data, 'transports', []),
                        'capabilities' => data_get($data, 'capabilities', []),
                        'spool' => [
                            'supported' => (bool) data_get($data, 'spoolSupported', false),
                            'queueDepth' => (int) data_get($data, 'queueDepth', 0),
                        ],
                        'reconnectMetadata' => data_get($data, 'reconnectMetadata', []),
                    ],
                ]
            ),
            'opened_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        $device->metadata = $this->mergeOperationalMetadata(
            $device->metadata ?? [],
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []
        );
        $device->save();

        event(new BridgeConnected($outletId, (int) $device->id, (string) $device->device_key));
        event(new BridgeLifecycleConnected($outletId, (int) $device->id, (string) $device->device_key));
        if ($runtimeState === 'recovering') {
            event(new SpoolRecovered($outletId, (int) $device->id, $this->recoverableReplayCount($outletId, (int) $device->id), $this->resumeMarkerForOutlet($outletId, (int) $device->id)));
        }
        $this->recordDeviceEvent($outletId, (int) $device->id, (int) $session->id, 'session_opened', []);

        return $session;
    }

    /** @param array<string,mixed> $data */
    public function closeSession(User $user, int $sessionId, array $data): HardwareDeviceSession
    {
        return $this->closeSessionForContext(HardwareBridgeAuthContext::fromUser($user), $sessionId, $data);
    }

    /** @param array<string,mixed> $data */
    public function closeSessionForContext(HardwareBridgeAuthContext $context, int $sessionId, array $data): HardwareDeviceSession
    {
        $session = $this->resolveSessionForContext($context, $sessionId);

        $session->status = 'closed';
        $session->closed_at = now();
        $session->save();

        $device = HardwareBridgeDevice::query()->find($session->hardware_bridge_device_id);
        if ($device instanceof HardwareBridgeDevice) {
            event(new BridgeDisconnected((int) $session->outlet_id, (int) $device->id, (string) $device->device_key, (string) ($data['reason'] ?? 'closed')));
            event(new BridgeLifecycleDisconnected((int) $session->outlet_id, (int) $device->id, (string) $device->device_key, (string) ($data['reason'] ?? 'closed')));
            $this->emitQueueSignals((int) $session->outlet_id, (int) $device->id, 'disconnected', $device->metadata ?? []);
        }
        $this->recordDeviceEvent((int) $session->outlet_id, (int) $session->hardware_bridge_device_id, (int) $session->id, 'session_closed', [
            'reason' => $data['reason'] ?? null,
        ]);

        return $session->fresh()
            ?? throw (new ModelNotFoundException)->setModel(HardwareDeviceSession::class, [(string) $sessionId]);
    }

    /** @return Collection<int, HardwareBridgeDevice> */
    public function listDevices(User $user, int $outletId): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        return HardwareBridgeDevice::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->get();
    }

    public function isBridgeOnlineForOutlet(int $outletId): bool
    {
        $graceSeconds = max(60, (int) config('hardware.bridge_online_grace_seconds', 120));

        return HardwareBridgeDevice::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'active')
            ->whereNull('disabled_at')
            ->whereNull('revoked_at')
            ->where('last_seen_at', '>=', now()->subSeconds($graceSeconds))
            ->exists();
    }

    /** @return list<array<string, mixed>> */
    public function listDeviceSummaries(User $user, int $outletId): array
    {
        return $this->listDevices($user, $outletId)
            ->map(fn (HardwareBridgeDevice $device): array => $this->summarizeDevice($device))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function summarizeDevice(HardwareBridgeDevice $device): array
    {
        $metadata = is_array($device->metadata) ? $device->metadata : [];
        $capabilities = is_array($device->capabilities) ? $device->capabilities : [];
        $provisioning = (array) ($metadata['provisioning'] ?? []);
        $auth = (array) ($metadata['auth'] ?? []);
        $tokenHealth = (array) ($auth['tokenHealth'] ?? []);
        $rotation = (array) ($auth['rotation'] ?? []);
        $watchdog = (array) ($metadata['watchdog'] ?? []);
        $deployment = (array) ($metadata['deployment'] ?? []);
        $updates = (array) ($metadata['updates'] ?? []);
        $transportHints = $this->normalizeTransportHints($metadata, $capabilities);

        return [
            'id' => (int) $device->id,
            'outletId' => (int) $device->outlet_id,
            'deviceKey' => (string) $device->device_key,
            'displayLabel' => $device->display_label,
            'status' => (string) $device->status,
            'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
            'revokedAt' => $device->revoked_at?->toIso8601String(),
            'disabledAt' => $device->disabled_at?->toIso8601String(),
            'reconnectCount' => (int) $device->reconnect_count,
            'connectionHint' => $this->resolveConnectionHint($transportHints, $capabilities),
            'transportHints' => $transportHints,
            'provisioning' => [
                'status' => (string) ($provisioning['status'] ?? ($provisioning['pairingTokenRef'] ?? null ? 'paired' : 'unpaired')),
                'pairedAt' => isset($provisioning['pairedAt']) ? (string) $provisioning['pairedAt'] : null,
                'pairedOutletId' => isset($provisioning['pairedOutletId']) ? (string) $provisioning['pairedOutletId'] : null,
                'deviceFingerprint' => isset($auth['deviceFingerprint']) ? (string) $auth['deviceFingerprint'] : null,
                'tokenHealth' => (string) ($tokenHealth['status'] ?? 'unknown'),
                'tokenRotationDue' => (bool) ($rotation['rotationDue'] ?? false),
            ],
            'watchdog' => [
                'state' => (string) ($watchdog['state'] ?? 'healthy'),
                'restartCount' => (int) ($watchdog['restartCount'] ?? 0),
                'crashCount' => (int) ($watchdog['crashCount'] ?? 0),
                'stalledSpoolDetected' => (bool) ($watchdog['stalledSpoolDetected'] ?? false),
                'freezeDetected' => (bool) ($watchdog['freezeDetected'] ?? false),
            ],
            'runtime' => [
                'version' => (string) ($metadata['runtimeVersion'] ?? 'unknown'),
                'deploymentMode' => (string) ($deployment['deploymentMode'] ?? 'headless'),
                'serviceMode' => (string) ($deployment['serviceMode'] ?? 'unknown'),
                'trayMode' => (string) ($deployment['trayMode'] ?? 'unknown'),
                'updateChannel' => (string) ($updates['channel'] ?? 'stable'),
                'updateAvailable' => (bool) ($updates['available'] ?? false),
                'updateTargetVersion' => isset($updates['targetVersion']) ? (string) $updates['targetVersion'] : null,
                'updateRestartRequired' => (bool) ($updates['restartRequired'] ?? false),
            ],
            'runtimeState' => HardwareRuntimeContract::normalizeRuntimeState(
                isset($metadata['runtimeState']) ? (string) $metadata['runtimeState'] : null,
                'connected'
            ),
            'capabilitiesSummary' => $this->summarizeCapabilities($capabilities, $metadata),
        ];
    }

    /** @param array<string, mixed> $capabilities @param array<string, mixed> $metadata @return list<string> */
    private function normalizeTransportHints(array $metadata, array $capabilities): array
    {
        $hints = [];
        if (isset($metadata['transportHints']) && is_array($metadata['transportHints'])) {
            foreach ($metadata['transportHints'] as $hint) {
                if (is_string($hint) && trim($hint) !== '') {
                    $hints[] = strtolower(trim($hint));
                }
            }
        }
        if ($capabilities['bluetooth'] ?? false) {
            $hints[] = 'bluetooth';
        }
        if (($capabilities['lan'] ?? false) || ($capabilities['network'] ?? false)) {
            $hints[] = 'lan';
        }

        return array_values(array_unique($hints));
    }

    /** @param list<string> $transportHints @param array<string, mixed> $capabilities */
    private function resolveConnectionHint(array $transportHints, array $capabilities): string
    {
        foreach ($transportHints as $hint) {
            if (str_contains($hint, 'bluetooth')) {
                return 'bluetooth';
            }
            if (str_contains($hint, 'lan') || str_contains($hint, 'ethernet')) {
                return 'lan';
            }
        }
        if ($capabilities['bluetooth'] ?? false) {
            return 'bluetooth';
        }
        if (($capabilities['lan'] ?? false) || ($capabilities['network'] ?? false)) {
            return 'lan';
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $capabilities @param array<string, mixed> $metadata @return array{transports: list<string>, capabilities: list<string>, spoolSupported: bool} */
    private function summarizeCapabilities(array $capabilities, array $metadata): array
    {
        $capabilityNames = [];
        foreach ($capabilities as $name => $value) {
            if ($value === true) {
                $capabilityNames[] = (string) $name;
            }
        }

        $transports = [];
        foreach (['transports', 'capabilities'] as $key) {
            $bucket = $capabilities[$key] ?? data_get($metadata, $key);
            if (! is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $transports[] = trim($entry);
                }
            }
        }

        $spoolSupported = (bool) data_get($capabilities, 'spool.supported', false)
            || (bool) data_get($metadata, 'spoolContract', false);

        foreach ($capabilities as $name => $value) {
            if (is_string($name) && str_contains(strtolower($name), 'spool') && (bool) $value) {
                $spoolSupported = true;
            }
        }

        return [
            'transports' => array_values(array_unique($transports !== [] ? $transports : ['polling'])),
            'capabilities' => array_values(array_unique($capabilityNames)),
            'spoolSupported' => $spoolSupported,
        ];
    }

    /** @param array<string,mixed> $data */
    public function updateDeviceState(User $user, int $deviceId, string $state, array $data): HardwareBridgeDevice
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $device = HardwareBridgeDevice::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->whereKey($deviceId)
            ->first();
        if (! $device instanceof HardwareBridgeDevice) {
            throw (new ModelNotFoundException)->setModel(HardwareBridgeDevice::class, [(string) $deviceId]);
        }

        $metadata = $this->mergeOperationalMetadata(
            $device->metadata ?? [],
            isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []
        );
        if (! empty($data['reason'])) {
            $metadata['auth'] = array_merge((array) ($metadata['auth'] ?? []), [
                'lastStateReason' => (string) $data['reason'],
            ]);
        }
        $device->metadata = $metadata;

        if ($state === 'disabled') {
            $device->status = 'disabled';
            $device->disabled_at = now();
            $device->revoked_at = null;
        }
        if ($state === 'revoked') {
            $device->status = 'revoked';
            $device->revoked_at = now();
            $device->disabled_at = null;
            $this->deviceAuthService->revokeCredentials($device);
        }

        $device->save();
        $this->recordDeviceEvent((int) $device->outlet_id, (int) $device->id, null, 'device_'.$state, [
            'reason' => $data['reason'] ?? null,
        ]);

        return $device->fresh()
            ?? throw (new ModelNotFoundException)->setModel(HardwareBridgeDevice::class, [(string) $deviceId]);
    }

    /**
     * System/internal enqueue for print pipeline (no user outlet scope).
     *
     * @param  array<string,mixed>  $payload
     * @return array{command: HardwareCommandLog, deduplicated: bool}
     */
    public function enqueueSystemCommand(
        int $outletId,
        string $deviceKey,
        string $commandType,
        string $idempotencyKey,
        array $payload,
        ?int $sessionId = null,
    ): array {
        $device = $this->resolveActiveDevice($outletId, $deviceKey);

        $existing = HardwareCommandLog::query()
            ->where('outlet_id', $outletId)
            ->where('idempotency_key', trim($idempotencyKey))
            ->first();
        if ($existing instanceof HardwareCommandLog) {
            return ['command' => $existing, 'deduplicated' => true];
        }

        $command = HardwareCommandLog::query()->create([
            'outlet_id' => $outletId,
            'hardware_bridge_device_id' => (int) $device->id,
            'hardware_device_session_id' => $sessionId,
            'command_type' => $commandType,
            'status' => 'pending',
            'idempotency_key' => trim($idempotencyKey),
            'payload' => $payload,
            'max_retries' => max(0, (int) config('hardware.default_max_retries', 3)),
            'next_retry_at' => null,
        ]);

        event(new CommandReceived($outletId, (int) $command->id, (string) $command->command_type, HardwareRuntimeContract::toSpoolStatus((string) $command->status)));
        $this->recordDeviceEvent($outletId, (int) $device->id, $sessionId, 'command_enqueued', [
            'commandId' => (int) $command->id,
            'commandType' => (string) $command->command_type,
            'source' => 'system_print_bridge',
        ]);

        return ['command' => $command, 'deduplicated' => false];
    }

    public function resolveActiveDevice(int $outletId, string $deviceKey): HardwareBridgeDevice
    {
        return $this->resolveUsableDevice($outletId, $deviceKey);
    }

    public function resolveDefaultDeviceForOutlet(int $outletId): HardwareBridgeDevice
    {
        $device = HardwareBridgeDevice::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'active')
            ->whereNull('disabled_at')
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->first();

        if (! $device instanceof HardwareBridgeDevice) {
            throw ValidationException::withMessages([
                'deviceKey' => ['No active hardware bridge device is registered for this outlet.'],
            ]);
        }

        return $device;
    }

    /** @param array<string,mixed> $data @return array{command: HardwareCommandLog, deduplicated: bool} */
    public function enqueueCommand(User $user, array $data): array
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);
        $device = $this->resolveUsableDevice($outletId, (string) $data['deviceKey']);
        $idempotencyKey = trim((string) $data['idempotencyKey']);

        $existing = HardwareCommandLog::query()
            ->where('outlet_id', $outletId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing instanceof HardwareCommandLog) {
            return ['command' => $existing, 'deduplicated' => true];
        }

        $command = HardwareCommandLog::query()->create([
            'outlet_id' => $outletId,
            'hardware_bridge_device_id' => (int) $device->id,
            'hardware_device_session_id' => isset($data['sessionId']) ? (int) $data['sessionId'] : null,
            'command_type' => (string) $data['commandType'],
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
            'payload' => isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : null,
            'max_retries' => isset($data['maxRetries']) ? (int) $data['maxRetries'] : max(0, (int) config('hardware.default_max_retries', 3)),
            'next_retry_at' => isset($data['nextRetryAt']) ? $data['nextRetryAt'] : null,
        ]);
        event(new CommandReceived($outletId, (int) $command->id, (string) $command->command_type, HardwareRuntimeContract::toSpoolStatus((string) $command->status)));

        $this->recordDeviceEvent($outletId, (int) $device->id, isset($data['sessionId']) ? (int) $data['sessionId'] : null, 'command_enqueued', [
            'commandId' => (int) $command->id,
            'commandType' => (string) $command->command_type,
        ]);

        return ['command' => $command, 'deduplicated' => false];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *   commands: list<array<string,mixed>>,
     *   hasMore: bool,
     *   resumeMarker: ?int,
     *   latestCommandId: ?int,
     *   limit: int
     * }
     */
    public function pullCommands(User $user, array $filters): array
    {
        return $this->pullCommandsForContext(HardwareBridgeAuthContext::fromUser($user), $filters);
    }

    /** @param array<string,mixed> $filters */
    public function pullCommandsForContext(HardwareBridgeAuthContext $context, array $filters): array
    {
        $outletId = (int) $filters['outletId'];
        $context->assertOutletAllowed($outletId, $this->outletAccessResolver);
        $device = $this->resolveUsableDevice($outletId, (string) $filters['deviceKey']);
        $context->assertDeviceMatches($device);
        $afterCommandId = isset($filters['afterCommandId']) ? (int) $filters['afterCommandId'] : null;
        $limit = min(100, max(1, (int) ($filters['limit'] ?? config('hardware.pull_command_default_limit', 25))));

        $query = HardwareCommandLog::query()
            ->where('outlet_id', $outletId)
            ->where('hardware_bridge_device_id', (int) $device->id)
            ->whereIn('status', ['pending', 'processing', 'replay_pending', 'failed'])
            ->orderBy('id');

        if ($afterCommandId !== null && $afterCommandId > 0) {
            $query->where('id', '>', $afterCommandId);
        }

        /** @var SupportCollection<int, HardwareCommandLog> $rows */
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $commands = $rows->take($limit)->map(fn (HardwareCommandLog $command): array => [
            'commandId' => (int) $command->id,
            'id' => (int) $command->id,
            'outletId' => (int) $command->outlet_id,
            'deviceId' => (int) $command->hardware_bridge_device_id,
            'sessionId' => $command->hardware_device_session_id !== null ? (int) $command->hardware_device_session_id : null,
            'commandType' => (string) $command->command_type,
            'payload' => $command->payload,
            'status' => (string) $command->status,
            'spoolStatus' => HardwareRuntimeContract::toSpoolStatus((string) $command->status),
            'legacyStatus' => HardwareRuntimeContract::toLegacyStatus(HardwareRuntimeContract::toSpoolStatus((string) $command->status)),
            'idempotencyKey' => (string) $command->idempotency_key,
            'retryCount' => (int) $command->retry_count,
            'maxRetries' => (int) $command->max_retries,
            'nextRetryAt' => $command->next_retry_at?->toIso8601String(),
            'deadLetteredAt' => $command->dead_lettered_at?->toIso8601String(),
            'createdAt' => $command->created_at?->toIso8601String(),
            'updatedAt' => $command->updated_at?->toIso8601String(),
        ])->values()->all();

        $latestCommandId = count($commands) > 0 ? (int) data_get($commands, (string) (count($commands) - 1).'.id') : $afterCommandId;
        $this->recordDeviceEvent($outletId, (int) $device->id, null, 'commands_pulled', [
            'afterCommandId' => $afterCommandId,
            'returned' => count($commands),
            'limit' => $limit,
            'hasMore' => $hasMore,
        ]);

        return [
            'commands' => $commands,
            'hasMore' => $hasMore,
            'resumeMarker' => $this->resumeMarkerForOutlet($outletId, (int) $device->id),
            'latestCommandId' => $latestCommandId,
            'limit' => $limit,
        ];
    }

    /** @param array<string,mixed> $data */
    public function acknowledgeCommand(User $user, int $commandId, bool $isAck, array $data): HardwareCommandLog
    {
        return $this->acknowledgeCommandForContext(HardwareBridgeAuthContext::fromUser($user), $commandId, $isAck, $data);
    }

    /** @param array<string,mixed> $data */
    public function acknowledgeCommandForContext(HardwareBridgeAuthContext $context, int $commandId, bool $isAck, array $data): HardwareCommandLog
    {
        $command = $this->resolveCommandForContext($context, $commandId);

        if ($isAck && in_array(HardwareRuntimeContract::toSpoolStatus((string) $command->status), ['acknowledged', 'dead_letter'], true)) {
            return $command;
        }
        if (! $isAck && in_array(HardwareRuntimeContract::toSpoolStatus((string) $command->status), ['dead_letter'], true)) {
            return $command;
        }

        if ($isAck) {
            $command->status = 'acknowledged';
            $command->ack_payload = isset($data['ackPayload']) && is_array($data['ackPayload']) ? $data['ackPayload'] : null;
            $command->acked_at = now();
            event(new HardwareCommandAcknowledged((int) $command->outlet_id, (int) $command->id, (string) $command->command_type, 'acknowledged'));
        } else {
            $command->status = 'failed';
            $command->nack_payload = isset($data['nackPayload']) && is_array($data['nackPayload']) ? $data['nackPayload'] : null;
            $command->nacked_at = now();
            $command->retry_count = (int) $command->retry_count + 1;
            $command->last_error_code = isset($data['errorCode']) ? (string) $data['errorCode'] : null;
            $command->last_error_message = isset($data['errorMessage']) ? (string) $data['errorMessage'] : null;
            if ((int) $command->retry_count > (int) $command->max_retries) {
                $command->status = 'dead_letter';
                $command->dead_lettered_at = now();
            } else {
                $command->status = 'replay_pending';
                $command->next_retry_at = now()->addSeconds($this->retryBackoffSeconds((int) $command->retry_count));
            }
        }

        $command->save();
        event(new CommandAcknowledged(
            (int) $command->outlet_id,
            (int) $command->id,
            (string) $command->command_type,
            HardwareRuntimeContract::toSpoolStatus((string) $command->status),
            (int) $command->retry_count
        ));

        $this->recordDeviceEvent((int) $command->outlet_id, (int) $command->hardware_bridge_device_id, $command->hardware_device_session_id, 'command_'.$command->status, [
            'commandId' => (int) $command->id,
            'commandType' => (string) $command->command_type,
        ]);

        return $command->fresh()
            ?? throw (new ModelNotFoundException)->setModel(HardwareCommandLog::class, [(string) $commandId]);
    }

    private function resolveRuntimeState(array $data, HardwareBridgeDevice $device): string
    {
        $state = HardwareRuntimeContract::normalizeRuntimeState(isset($data['runtimeState']) ? (string) $data['runtimeState'] : null, 'connected');
        $staleMinutes = max(1, (int) config('hardware.session_stale_after_minutes', 15));
        $last = $device->last_seen_at?->toImmutable();
        if ($last !== null && $last->lte(CarbonImmutable::now()->subMinutes($staleMinutes))) {
            return 'stale';
        }

        return $state;
    }

    private function heartbeatContract(array $data, HardwareBridgeDevice $device, string $runtimeState): array
    {
        $queueDepth = max(0, (int) data_get($data, 'queueDepth', 0));

        return [
            'capabilities' => array_merge($device->capabilities ?? [], [
                'transports' => data_get($data, 'transports', []),
                'capabilities' => data_get($data, 'capabilities', []),
                'spool' => [
                    'supported' => (bool) data_get($data, 'spoolSupported', false),
                ],
            ]),
            'metadata' => [
                'runtimeState' => $runtimeState,
                'queueDepth' => $queueDepth,
                'reconnectMetadata' => data_get($data, 'reconnectMetadata', []),
                'resumeMarker' => $this->resumeMarkerForOutlet((int) $device->outlet_id, (int) $device->id),
                'heartbeatAt' => now()->toIso8601String(),
                'watchdog' => [
                    'state' => data_get($data, 'metadata.watchdog.state', $runtimeState),
                    'stalledSpoolDetected' => (bool) data_get($data, 'metadata.watchdog.stalledSpoolDetected', false),
                    'freezeDetected' => (bool) data_get($data, 'metadata.watchdog.freezeDetected', false),
                    'restartCount' => (int) data_get($data, 'metadata.watchdog.restartCount', 0),
                    'crashCount' => (int) data_get($data, 'metadata.watchdog.crashCount', 0),
                ],
                'spoolContract' => [
                    'statuses' => ['pending', 'processing', 'acknowledged', 'failed', 'replay_pending', 'dead_letter'],
                    'legacy' => ['queued', 'nacked'],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function emitQueueSignals(int $outletId, int $deviceId, string $runtimeState, array $metadata): void
    {
        $queueDepth = max(0, (int) ($metadata['queueDepth'] ?? 0));
        $deadLetterCount = (int) HardwareCommandLog::query()
            ->where('outlet_id', $outletId)
            ->where('hardware_bridge_device_id', $deviceId)
            ->where('status', 'dead_letter')
            ->count();

        if (in_array($runtimeState, ['degraded', 'stale', 'disconnected'], true) || $queueDepth >= max(1, (int) config('hardware.degraded_queue_depth_threshold', 20))) {
            event(new QueueDegraded($outletId, $deviceId, $queueDepth, $deadLetterCount));
        }
    }

    private function retryBackoffSeconds(int $retryCount): int
    {
        $base = max(1, (int) config('hardware.retry_backoff_base_seconds', 15));
        $max = max($base, (int) config('hardware.retry_backoff_cap_seconds', 300));

        return min($max, $base * (2 ** max(0, $retryCount - 1)));
    }

    private function resumeMarkerForOutlet(int $outletId, int $deviceId): ?int
    {
        return HardwareCommandLog::query()
            ->where('outlet_id', $outletId)
            ->where('hardware_bridge_device_id', $deviceId)
            ->where('status', 'acknowledged')
            ->max('id');
    }

    private function recoverableReplayCount(int $outletId, int $deviceId): int
    {
        return (int) HardwareCommandLog::query()
            ->where('outlet_id', $outletId)
            ->where('hardware_bridge_device_id', $deviceId)
            ->whereIn('status', ['replay_pending', 'failed'])
            ->count();
    }

    private function resolveUsableDevice(int $outletId, string $deviceKey): HardwareBridgeDevice
    {
        $device = HardwareBridgeDevice::query()
            ->where('outlet_id', $outletId)
            ->where('device_key', trim($deviceKey))
            ->first();
        if (! $device instanceof HardwareBridgeDevice) {
            throw ValidationException::withMessages([
                'deviceKey' => ['Hardware bridge device is not registered for this outlet.'],
            ]);
        }
        if (! $device->isUsable()) {
            throw ValidationException::withMessages([
                'deviceKey' => ['Hardware bridge device is revoked or disabled.'],
            ]);
        }

        return $device;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming @return array<string,mixed> */
    private function mergeOperationalMetadata(array $existing, array $incoming): array
    {
        $merged = array_merge($existing, $incoming);
        $merged['provisioning'] = array_merge((array) ($existing['provisioning'] ?? []), (array) ($incoming['provisioning'] ?? []));
        $merged['auth'] = array_merge((array) ($existing['auth'] ?? []), (array) ($incoming['auth'] ?? []));
        $merged['watchdog'] = array_merge((array) ($existing['watchdog'] ?? []), (array) ($incoming['watchdog'] ?? []));
        $merged['spoolHealth'] = array_merge((array) ($existing['spoolHealth'] ?? []), (array) ($incoming['spoolHealth'] ?? []));
        $merged['updates'] = array_merge((array) ($existing['updates'] ?? []), (array) ($incoming['updates'] ?? []));
        $merged['deployment'] = array_merge((array) ($existing['deployment'] ?? []), (array) ($incoming['deployment'] ?? []));

        $merged['runtimeVersion'] = (string) ($merged['runtimeVersion'] ?? config('hardware.runtime.version_contract', '16.3.0'));
        $merged['auth'] = array_merge([
            'tokenHealth' => ['status' => 'unknown'],
            'rotation' => ['rotationDue' => false],
        ], (array) ($merged['auth'] ?? []));
        $merged['updates'] = array_merge([
            'channel' => config('hardware.runtime.update_channel_default', 'stable'),
            'available' => false,
            'restartRequired' => false,
            'deferred' => false,
        ], (array) ($merged['updates'] ?? []));
        $merged['deployment'] = array_merge([
            'deploymentMode' => 'headless',
            'serviceMode' => 'unknown',
            'trayMode' => 'unknown',
            'headless' => true,
        ], (array) ($merged['deployment'] ?? []));

        return $merged;
    }

    private function resolveCommandForContext(HardwareBridgeAuthContext $context, int $commandId): HardwareCommandLog
    {
        if ($context->device !== null) {
            $command = HardwareCommandLog::query()
                ->where('outlet_id', (int) $context->device->outlet_id)
                ->where('hardware_bridge_device_id', (int) $context->device->id)
                ->whereKey($commandId)
                ->first();
        } else {
            $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($context->user);
            $command = HardwareCommandLog::query()
                ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
                ->whereKey($commandId)
                ->first();
        }

        if ($command === null) {
            throw (new ModelNotFoundException)->setModel(HardwareCommandLog::class, [(string) $commandId]);
        }

        return $command;
    }

    private function resolveSessionForContext(HardwareBridgeAuthContext $context, int $sessionId): HardwareDeviceSession
    {
        if ($context->device !== null) {
            $session = HardwareDeviceSession::query()
                ->where('outlet_id', (int) $context->device->outlet_id)
                ->where('hardware_bridge_device_id', (int) $context->device->id)
                ->whereKey($sessionId)
                ->first();
        } else {
            $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($context->user);
            $session = HardwareDeviceSession::query()
                ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
                ->whereKey($sessionId)
                ->first();
        }

        if ($session === null) {
            throw (new ModelNotFoundException)->setModel(HardwareDeviceSession::class, [(string) $sessionId]);
        }

        return $session;
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function recordDeviceEvent(int $outletId, ?int $deviceId, ?int $sessionId, string $type, array $payload): void
    {
        HardwareDeviceEvent::query()->create([
            'outlet_id' => $outletId,
            'hardware_bridge_device_id' => $deviceId,
            'hardware_device_session_id' => $sessionId,
            'event_type' => $type,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
