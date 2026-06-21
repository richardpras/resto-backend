<?php

namespace App\Modules\Hardware\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Hardware\Http\Requests\InitHardwarePairingRequest;
use App\Modules\Hardware\Http\Requests\RedeemHardwarePairingRequest;
use App\Modules\Hardware\Http\Requests\RefreshHardwareCredentialRequest;
use App\Modules\Hardware\Services\HardwareDeviceAuthService;
use App\Modules\Hardware\Services\HardwarePairingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class HardwarePairingController extends Controller
{
    public function __construct(
        private readonly HardwarePairingService $pairingService,
        private readonly HardwareDeviceAuthService $deviceAuthService,
    ) {}

    public function init(InitHardwarePairingRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $result = $this->pairingService->initPairing(
            $user,
            (int) $request->validated('outletId'),
            $request->validated('displayLabel'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Kode pairing berhasil dibuat.',
            'data' => $result,
            'meta' => null,
        ]);
    }

    public function redeem(RedeemHardwarePairingRequest $request): JsonResponse
    {
        $result = $this->pairingService->redeemPairing($request->validated());
        $device = $result['device'];

        return response()->json([
            'success' => true,
            'message' => 'Bridge berhasil terhubung.',
            'data' => [
                'outletId' => (int) $result['outletId'],
                'outletName' => $result['outletName'],
                'deviceKey' => (string) $result['deviceKey'],
                'displayLabel' => $result['displayLabel'],
                'deviceId' => (int) $device->id,
                'accessToken' => $result['accessToken'],
                'refreshToken' => $result['refreshToken'],
                'expiresAt' => $result['expiresAt'],
                'refreshExpiresAt' => $result['refreshExpiresAt'],
                'tokenType' => 'Bearer',
            ],
            'meta' => null,
        ]);
    }

    public function refresh(RefreshHardwareCredentialRequest $request): JsonResponse
    {
        $result = $this->deviceAuthService->refreshCredentials((string) $request->validated('refreshToken'));

        return response()->json([
            'success' => true,
            'message' => 'Token bridge diperbarui.',
            'data' => [
                'accessToken' => $result['accessToken'],
                'refreshToken' => $result['refreshToken'],
                'expiresAt' => $result['expiresAt']->toIso8601String(),
                'refreshExpiresAt' => $result['refreshExpiresAt']->toIso8601String(),
                'tokenType' => 'Bearer',
                'outletId' => (int) $result['device']->outlet_id,
                'deviceKey' => (string) $result['device']->device_key,
            ],
            'meta' => null,
        ]);
    }
}
