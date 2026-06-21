<?php

namespace App\Modules\Hardware\Services;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Hardware\Domain\HardwareDeviceCredential;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class HardwareDeviceAuthService
{
    /** @return array{accessToken: string, refreshToken: string, expiresAt: CarbonImmutable, refreshExpiresAt: CarbonImmutable} */
    public function issueTokenPair(HardwareBridgeDevice $device): array
    {
        $accessToken = $this->generateToken();
        $refreshToken = $this->generateToken();
        $now = CarbonImmutable::now();
        $expiresAt = $now->addDays(max(1, (int) config('hardware.device_auth.access_token_ttl_days', 30)));
        $refreshExpiresAt = $now->addDays(max(1, (int) config('hardware.device_auth.refresh_token_ttl_days', 365)));

        HardwareDeviceCredential::query()->updateOrCreate(
            ['hardware_bridge_device_id' => (int) $device->id],
            [
                'token_hash' => $this->hashToken($accessToken),
                'refresh_token_hash' => $this->hashToken($refreshToken),
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'last_rotated_at' => $now,
                'revoked_at' => null,
            ],
        );

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'expiresAt' => $expiresAt,
            'refreshExpiresAt' => $refreshExpiresAt,
        ];
    }

    public function authenticateAccessToken(?string $token): ?HardwareBridgeDevice
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $credential = HardwareDeviceCredential::query()
            ->where('token_hash', $this->hashToken($token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $credential instanceof HardwareDeviceCredential) {
            return null;
        }

        $device = $credential->device;
        if (! $device instanceof HardwareBridgeDevice || ! $device->isUsable()) {
            return null;
        }

        return $device;
    }

    /** @return array{accessToken: string, refreshToken: string, expiresAt: CarbonImmutable, refreshExpiresAt: CarbonImmutable, device: HardwareBridgeDevice} */
    public function refreshCredentials(string $refreshToken): array
    {
        $credential = HardwareDeviceCredential::query()
            ->where('refresh_token_hash', $this->hashToken($refreshToken))
            ->whereNull('revoked_at')
            ->where('refresh_expires_at', '>', now())
            ->first();

        if (! $credential instanceof HardwareDeviceCredential) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'refreshToken' => ['Invalid or expired refresh token.'],
            ]);
        }

        $device = $credential->device;
        if (! $device instanceof HardwareBridgeDevice || ! $device->isUsable()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'refreshToken' => ['Bridge device is no longer active.'],
            ]);
        }

        $tokens = $this->issueTokenPair($device);

        return [
            ...$tokens,
            'device' => $device->fresh() ?? $device,
        ];
    }

    public function revokeCredentials(HardwareBridgeDevice $device): void
    {
        HardwareDeviceCredential::query()
            ->where('hardware_bridge_device_id', (int) $device->id)
            ->update(['revoked_at' => now()]);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function generateToken(): string
    {
        return Str::random(64);
    }
}
