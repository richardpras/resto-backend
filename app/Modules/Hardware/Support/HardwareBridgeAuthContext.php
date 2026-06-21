<?php

namespace App\Modules\Hardware\Support;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

final class HardwareBridgeAuthContext
{
    public function __construct(
        public readonly ?User $user,
        public readonly ?HardwareBridgeDevice $device,
    ) {
        if ($this->user === null && $this->device === null) {
            throw new \InvalidArgumentException('Hardware bridge auth context requires a user or device.');
        }
    }

    public static function fromUser(User $user): self
    {
        return new self($user, null);
    }

    public static function fromDevice(HardwareBridgeDevice $device): self
    {
        return new self(null, $device);
    }

    public function isDeviceAuth(): bool
    {
        return $this->device !== null;
    }

    public function assertOutletAllowed(int $outletId, OutletAccessResolver $resolver): void
    {
        if ($this->device !== null) {
            if ((int) $this->device->outlet_id !== $outletId) {
                throw ValidationException::withMessages([
                    'outletId' => ['The authenticated bridge device cannot access this outlet.'],
                ]);
            }

            return;
        }

        $allowed = $resolver->allowedOutletIds($this->user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    public function assertDeviceMatches(HardwareBridgeDevice $device): void
    {
        if ($this->device === null) {
            return;
        }

        if ((int) $this->device->id !== (int) $device->id) {
            throw ValidationException::withMessages([
                'deviceKey' => ['The authenticated bridge device cannot act on another device.'],
            ]);
        }
    }
}
