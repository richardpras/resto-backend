<?php

namespace App\Modules\Terminals\Services;

use App\Models\Modules\Terminals\Domain\TerminalDevice;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TerminalDeviceService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerOrRefresh(User $user, array $data): TerminalDevice
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);
        $deviceKey = trim((string) $data['deviceKey']);
        $displayLabel = isset($data['displayLabel']) ? trim((string) $data['displayLabel']) : null;
        /** @var array<string, mixed>|null $capabilities */
        $capabilities = isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : null;

        /** @var TerminalDevice $terminal */
        $terminal = TerminalDevice::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'device_key' => $deviceKey],
            [
                'display_label' => $displayLabel !== '' ? $displayLabel : null,
                'capabilities' => $capabilities,
                'status' => 'active',
                'revoked_at' => null,
            ]
        );

        return $terminal;
    }

    /**
     * @param  array<string, mixed>|null  $sessionMetadata
     */
    public function heartbeat(User $user, int $outletId, string $deviceKey, ?array $sessionMetadata = null): TerminalDevice
    {
        $this->assertOutletAllowed($user, $outletId);
        $terminal = TerminalDevice::query()
            ->where('outlet_id', $outletId)
            ->where('device_key', trim($deviceKey))
            ->first();
        if ($terminal === null) {
            throw ValidationException::withMessages([
                'deviceKey' => ['Terminal is not registered for this outlet.'],
            ]);
        }
        if (! $terminal->isUsable()) {
            throw ValidationException::withMessages([
                'deviceKey' => ['Terminal is revoked or disabled.'],
            ]);
        }

        $now = CarbonImmutable::now();
        $gapMinutes = max(1, (int) config('terminals.reconnect_gap_minutes', 30));
        $last = $terminal->last_seen_at?->toImmutable();
        if ($last !== null && $last->lte($now->subMinutes($gapMinutes))) {
            $terminal->reconnect_count = (int) $terminal->reconnect_count + 1;
        }

        $terminal->last_seen_at = $now;
        if ($sessionMetadata !== null) {
            $terminal->session_metadata = $sessionMetadata;
        }
        $terminal->save();

        return $terminal->fresh()
            ?? throw (new ModelNotFoundException)->setModel(TerminalDevice::class, [(string) $terminal->id]);
    }

    /** @return Collection<int, TerminalDevice> */
    public function listForOutlet(User $user, int $outletId)
    {
        $this->assertOutletAllowed($user, $outletId);

        return TerminalDevice::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->get();
    }

    public function disable(User $user, int $terminalId): TerminalDevice
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $terminal = TerminalDevice::query()
            ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
            ->whereKey($terminalId)
            ->first();
        if ($terminal === null) {
            throw (new ModelNotFoundException)->setModel(TerminalDevice::class, [(string) $terminalId]);
        }

        $terminal->status = 'disabled';
        $terminal->revoked_at = now();
        $terminal->save();

        return $terminal;
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
