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
    ) {}

    /** @param array<string,mixed> $data */
    public function registerDevice(User $user, array $data): HardwareBridgeDevice
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        $device = HardwareBridgeDevice::query()->updateOrCreate(
            [
                'outlet_id' => $outletId,
                'device_key' => trim((string) $data['deviceKey']),
            ],
            [
                'display_label' => isset($data['displayLabel']) ? trim((string) $data['displayLabel']) : null,
                'capabilities' => isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : null,
                'metadata' => $this->mergeOperationalMetadata([], isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : []),
                'status' => 'active',
                'disabled_at' => null,
                'revoked_at' => null,
            ]
        );

        event(new BridgeConnected($outletId, (int) $device->id, (string) $device->device_key));
        event(new BridgeLifecycleConnected($outletId, (int) $device->id, (string) $device->device_key));
        if ((bool) data_get($data, 'metadata.bluetoothPaired', false)) {
            event(new BluetoothPrinterPaired($outletId, (int) $device->id, (string) $device->device_key));
        }

        $this->recordDeviceEvent($outletId, (int) $device->id, null, 'bridge_registered', ['deviceKey' => $device->device_key]);

        return $device->fresh()
            ?? throw (new ModelNotFoundException)->setModel(HardwareBridgeDevice::class, [(string) $device->id]);
    }

    /** @param array<string,mixed> $data */
    public function heartbeat(User $user, array $data): HardwareBridgeDevice
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);
        $device = $this->resolveUsableDevice($outletId, (string) $data['deviceKey']);

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
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);
        $device = $this->resolveUsableDevice($outletId, (string) $data['deviceKey']);

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
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $session = HardwareDeviceSession::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->whereKey($sessionId)
            ->first();
        if ($session === null) {
            throw (new ModelNotFoundException)->setModel(HardwareDeviceSession::class, [(string) $sessionId]);
        }

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
        $outletId = (int) $filters['outletId'];
        $this->assertOutletAllowed($user, $outletId);
        $device = $this->resolveUsableDevice($outletId, (string) $filters['deviceKey']);
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
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $command = HardwareCommandLog::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->whereKey($commandId)
            ->first();
        if ($command === null) {
            throw (new ModelNotFoundException)->setModel(HardwareCommandLog::class, [(string) $commandId]);
        }

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
