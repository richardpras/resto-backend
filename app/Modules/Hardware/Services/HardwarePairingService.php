<?php

namespace App\Modules\Hardware\Services;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Hardware\Domain\HardwarePairingCode;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwarePairingService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly HardwareBridgeService $bridgeService,
        private readonly HardwareDeviceAuthService $deviceAuthService,
    ) {}

    /** @return array{code: string, expiresAt: string, outletId: int} */
    public function initPairing(User $user, int $outletId, ?string $displayLabel = null): array
    {
        $this->assertOutletAllowed($user, $outletId);

        $code = $this->generatePairingCode();
        $expiresAt = CarbonImmutable::now()->addMinutes(max(1, (int) config('hardware.pairing.code_ttl_minutes', 15)));

        HardwarePairingCode::query()->create([
            'outlet_id' => $outletId,
            'code_hash' => $this->deviceAuthService->hashToken($code),
            'created_by_user_id' => (int) $user->id,
            'display_label' => $displayLabel !== null ? trim($displayLabel) : null,
            'expires_at' => $expiresAt,
        ]);

        return [
            'code' => $code,
            'expiresAt' => $expiresAt->toIso8601String(),
            'outletId' => $outletId,
        ];
    }

    /**
     * @param array{code: string, deviceKey: string, displayLabel?: string|null, fingerprint?: string|null, capabilities?: array<string,mixed>|null} $data
     * @return array{
     *   device: HardwareBridgeDevice,
     *   accessToken: string,
     *   refreshToken: string,
     *   expiresAt: string,
     *   refreshExpiresAt: string,
     *   outletId: int,
     *   deviceKey: string,
     *   displayLabel: string|null
     * }
     */
    public function redeemPairing(array $data): array
    {
        $code = preg_replace('/\D+/', '', (string) $data['code']) ?? '';
        $deviceKey = trim((string) $data['deviceKey']);
        $displayLabel = isset($data['displayLabel']) ? trim((string) $data['displayLabel']) : null;

        if ($code === '' || $deviceKey === '') {
            throw ValidationException::withMessages([
                'code' => ['Pairing code and device key are required.'],
            ]);
        }

        return DB::transaction(function () use ($code, $deviceKey, $displayLabel, $data): array {
            $pairing = HardwarePairingCode::query()
                ->where('code_hash', $this->deviceAuthService->hashToken($code))
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pairing instanceof HardwarePairingCode) {
                throw ValidationException::withMessages([
                    'code' => ['Pairing code is invalid, expired, or already used.'],
                ]);
            }

            $outletId = (int) $pairing->outlet_id;
            $label = $displayLabel !== '' ? $displayLabel : ($pairing->display_label ?: 'Komputer Printer Outlet');

            $metadata = [
                'provisioning' => [
                    'status' => 'paired',
                    'pairedAt' => now()->toIso8601String(),
                    'pairedOutletId' => $outletId,
                    'pairingTokenRef' => 'pair-'.$pairing->id,
                ],
            ];

            if (! empty($data['fingerprint'])) {
                $metadata['auth'] = [
                    'deviceFingerprint' => (string) $data['fingerprint'],
                ];
            }

            $device = $this->bridgeService->registerDeviceInternal(
                $outletId,
                $deviceKey,
                $label,
                isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : ['polling' => true],
                $metadata,
            );

            $tokens = $this->deviceAuthService->issueTokenPair($device);

            $pairing->consumed_at = now();
            $pairing->consumed_device_id = (int) $device->id;
            $pairing->save();

            $outlet = Outlet::query()->find($outletId);

            return [
                'device' => $device,
                'accessToken' => $tokens['accessToken'],
                'refreshToken' => $tokens['refreshToken'],
                'expiresAt' => $tokens['expiresAt']->toIso8601String(),
                'refreshExpiresAt' => $tokens['refreshExpiresAt']->toIso8601String(),
                'outletId' => $outletId,
                'outletName' => $outlet?->name,
                'deviceKey' => $deviceKey,
                'displayLabel' => $device->display_label,
            ];
        });
    }

    private function generatePairingCode(): string
    {
        $length = max(4, (int) config('hardware.pairing.code_length', 6));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
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
}
