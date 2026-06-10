<?php

namespace App\Modules\Hardware\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Hardware\Http\Requests\CloseHardwareSessionRequest;
use App\Modules\Hardware\Http\Requests\EnqueueHardwareCommandRequest;
use App\Modules\Hardware\Http\Requests\GetHardwareCommandsRequest;
use App\Modules\Hardware\Http\Requests\HardwareCommandAcknowledgeRequest;
use App\Modules\Hardware\Http\Requests\HardwareHeartbeatRequest;
use App\Modules\Hardware\Http\Requests\OpenHardwareSessionRequest;
use App\Modules\Hardware\Http\Requests\RegisterHardwareDeviceRequest;
use App\Modules\Hardware\Http\Requests\UpdateHardwareDeviceStateRequest;
use App\Modules\Hardware\Services\HardwareBridgeService;
use App\Support\HardwareRuntimeContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HardwareBridgeController extends Controller
{
    public function __construct(
        private readonly HardwareBridgeService $service,
    ) {}

    public function register(RegisterHardwareDeviceRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $device = $this->service->registerDevice($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware bridge device registered successfully.',
            'data' => $this->devicePayload($device),
            'meta' => null,
        ]);
    }

    public function heartbeat(HardwareHeartbeatRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $device = $this->service->heartbeat($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware heartbeat recorded.',
            'data' => $this->devicePayload($device),
            'meta' => null,
        ]);
    }

    public function openSession(OpenHardwareSessionRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $session = $this->service->openSession($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware session opened.',
            'data' => [
                'id' => (int) $session->id,
                'outletId' => (int) $session->outlet_id,
                'deviceId' => (int) $session->hardware_bridge_device_id,
                'status' => (string) $session->status,
                'openedAt' => $session->opened_at?->toIso8601String(),
                'closedAt' => $session->closed_at?->toIso8601String(),
            ],
            'meta' => null,
        ]);
    }

    public function closeSession(CloseHardwareSessionRequest $request, int $session): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $closed = $this->service->closeSession($user, $session, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware session closed.',
            'data' => [
                'id' => (int) $closed->id,
                'status' => (string) $closed->status,
                'closedAt' => $closed->closed_at?->toIso8601String(),
            ],
            'meta' => null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $outletId = (int) $request->query('outletId', 0);
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId query parameter is required.');

        $devices = $this->service->listDevices($user, $outletId);

        return response()->json([
            'success' => true,
            'message' => 'Hardware bridge devices retrieved successfully.',
            'data' => $devices->map(fn ($device): array => $this->devicePayload($device))->values()->all(),
            'meta' => null,
        ]);
    }

    public function disableDevice(UpdateHardwareDeviceStateRequest $request, int $device): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $updated = $this->service->updateDeviceState($user, $device, 'disabled', $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware bridge device disabled.',
            'data' => $this->devicePayload($updated),
            'meta' => null,
        ]);
    }

    public function revokeDevice(UpdateHardwareDeviceStateRequest $request, int $device): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $updated = $this->service->updateDeviceState($user, $device, 'revoked', $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware bridge device revoked.',
            'data' => $this->devicePayload($updated),
            'meta' => null,
        ]);
    }

    public function enqueueCommand(EnqueueHardwareCommandRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $result = $this->service->enqueueCommand($user, $request->validated());
        $command = $result['command'];

        return response()->json([
            'success' => true,
            'message' => $result['deduplicated'] ? 'Duplicate command suppressed by idempotency key.' : 'Hardware command queued.',
            'data' => [
                'commandId' => (int) $command->id,
                'id' => (int) $command->id,
                'outletId' => (int) $command->outlet_id,
                'deviceId' => (int) $command->hardware_bridge_device_id,
                'sessionId' => $command->hardware_device_session_id !== null ? (int) $command->hardware_device_session_id : null,
                'commandType' => (string) $command->command_type,
                'status' => (string) $command->status,
                'spoolStatus' => HardwareRuntimeContract::toSpoolStatus((string) $command->status),
                'legacyStatus' => HardwareRuntimeContract::toLegacyStatus(HardwareRuntimeContract::toSpoolStatus((string) $command->status)),
                'idempotencyKey' => (string) $command->idempotency_key,
                'retryCount' => (int) $command->retry_count,
                'maxRetries' => (int) $command->max_retries,
                'nextRetryAt' => $command->next_retry_at?->toIso8601String(),
                'deadLetteredAt' => $command->dead_lettered_at?->toIso8601String(),
                'deduplicated' => (bool) $result['deduplicated'],
            ],
            'meta' => null,
        ]);
    }

    public function pullCommands(GetHardwareCommandsRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $result = $this->service->pullCommands($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware commands pulled successfully.',
            'data' => $result,
            'meta' => null,
        ]);
    }

    public function ack(HardwareCommandAcknowledgeRequest $request, int $command): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $updated = $this->service->acknowledgeCommand($user, $command, true, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware command acknowledged.',
            'data' => $this->commandPayload($updated),
            'meta' => null,
        ]);
    }

    public function nack(HardwareCommandAcknowledgeRequest $request, int $command): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $updated = $this->service->acknowledgeCommand($user, $command, false, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Hardware command NACK recorded.',
            'data' => $this->commandPayload($updated),
            'meta' => null,
        ]);
    }

    private function devicePayload($device): array
    {
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
            'capabilities' => $device->capabilities,
            'metadata' => $device->metadata,
            'runtimeContract' => [
                'state' => HardwareRuntimeContract::normalizeRuntimeState((string) data_get($device->metadata, 'runtimeState', 'connected')),
                'resumeMarker' => data_get($device->metadata, 'resumeMarker'),
            ],
        ];
    }

    private function commandPayload($command): array
    {
        return [
            'commandId' => (int) $command->id,
            'id' => (int) $command->id,
            'outletId' => (int) $command->outlet_id,
            'deviceId' => (int) $command->hardware_bridge_device_id,
            'sessionId' => $command->hardware_device_session_id !== null ? (int) $command->hardware_device_session_id : null,
            'status' => (string) $command->status,
            'spoolStatus' => HardwareRuntimeContract::toSpoolStatus((string) $command->status),
            'legacyStatus' => HardwareRuntimeContract::toLegacyStatus(HardwareRuntimeContract::toSpoolStatus((string) $command->status)),
            'retryCount' => (int) $command->retry_count,
            'maxRetries' => (int) $command->max_retries,
            'nextRetryAt' => $command->next_retry_at?->toIso8601String(),
            'deadLetteredAt' => $command->dead_lettered_at?->toIso8601String(),
            'ackedAt' => $command->acked_at?->toIso8601String(),
            'nackedAt' => $command->nacked_at?->toIso8601String(),
        ];
    }
}
