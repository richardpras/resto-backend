<?php

namespace App\Modules\Terminals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Terminals\Http\Requests\RegisterTerminalDeviceRequest;
use App\Modules\Terminals\Http\Requests\TerminalHeartbeatRequest;
use App\Modules\Terminals\Services\TerminalDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TerminalDeviceController extends Controller
{
    public function __construct(
        private readonly TerminalDeviceService $terminalDeviceService,
    ) {}

    public function register(RegisterTerminalDeviceRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $terminal = $this->terminalDeviceService->registerOrRefresh($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Terminal registered successfully.',
            'data' => [
                'id' => (int) $terminal->id,
                'outletId' => (int) $terminal->outlet_id,
                'deviceKey' => (string) $terminal->device_key,
                'displayLabel' => $terminal->display_label,
                'status' => (string) $terminal->status,
                'capabilities' => $terminal->capabilities,
                'lastSeenAt' => $terminal->last_seen_at?->toIso8601String(),
                'reconnectCount' => (int) $terminal->reconnect_count,
            ],
            'meta' => null,
        ]);
    }

    public function heartbeat(TerminalHeartbeatRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $data = $request->validated();
        $terminal = $this->terminalDeviceService->heartbeat(
            $user,
            (int) $data['outletId'],
            (string) $data['deviceKey'],
            isset($data['sessionMetadata']) && is_array($data['sessionMetadata']) ? $data['sessionMetadata'] : null
        );

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat recorded.',
            'data' => [
                'id' => (int) $terminal->id,
                'outletId' => (int) $terminal->outlet_id,
                'lastSeenAt' => $terminal->last_seen_at?->toIso8601String(),
                'reconnectCount' => (int) $terminal->reconnect_count,
                'sessionMetadata' => $terminal->session_metadata,
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

        $terminals = $this->terminalDeviceService->listForOutlet($user, $outletId);

        return response()->json([
            'success' => true,
            'message' => 'Terminals retrieved successfully.',
            'data' => $terminals->map(static fn ($t): array => [
                'id' => (int) $t->id,
                'outletId' => (int) $t->outlet_id,
                'deviceKey' => (string) $t->device_key,
                'displayLabel' => $t->display_label,
                'status' => (string) $t->status,
                'capabilities' => $t->capabilities,
                'lastSeenAt' => $t->last_seen_at?->toIso8601String(),
                'revokedAt' => $t->revoked_at?->toIso8601String(),
                'reconnectCount' => (int) $t->reconnect_count,
            ])->values()->all(),
            'meta' => null,
        ]);
    }

    public function disable(Request $request, int $terminal): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $device = $this->terminalDeviceService->disable($user, $terminal);

        return response()->json([
            'success' => true,
            'message' => 'Terminal disabled.',
            'data' => [
                'id' => (int) $device->id,
                'status' => (string) $device->status,
                'revokedAt' => $device->revoked_at?->toIso8601String(),
            ],
            'meta' => null,
        ]);
    }
}
