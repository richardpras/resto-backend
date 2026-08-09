<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OutletReservationSettingsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function show(User $user, int $outletId): OutletReservationSetting
    {
        $this->assertOutletAllowed($user, $outletId);

        return $this->findOrCreateDefaults($outletId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, int $outletId, array $data): OutletReservationSetting
    {
        $this->assertOutletAllowed($user, $outletId);
        $settings = $this->findOrCreateDefaults($outletId);

        if (array_key_exists('publicEnabled', $data)) {
            $settings->public_enabled = (bool) $data['publicEnabled'];
        }
        if (array_key_exists('publicSlug', $data)) {
            $slug = Str::slug((string) $data['publicSlug']);
            if ($slug === '') {
                throw ValidationException::withMessages([
                    'publicSlug' => ['Public slug is required.'],
                ]);
            }
            $exists = OutletReservationSetting::query()
                ->where('public_slug', $slug)
                ->where('outlet_id', '!=', $outletId)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'publicSlug' => ['Public slug is already in use.'],
                ]);
            }
            $settings->public_slug = $slug;
        }
        if (array_key_exists('depositMode', $data)) {
            $settings->deposit_mode = (string) $data['depositMode'];
        }
        if (array_key_exists('depositPercent', $data)) {
            $settings->deposit_percent = $data['depositPercent'] !== null ? (float) $data['depositPercent'] : null;
        }
        if (array_key_exists('depositFlatAmount', $data)) {
            $settings->deposit_flat_amount = $data['depositFlatAmount'] !== null ? (float) $data['depositFlatAmount'] : null;
        }
        if (array_key_exists('preorderRequired', $data)) {
            $settings->preorder_required = (bool) $data['preorderRequired'];
        }
        if (array_key_exists('depositInstructions', $data)) {
            $settings->deposit_instructions = $data['depositInstructions'] !== null
                ? (string) $data['depositInstructions']
                : null;
        }
        if (array_key_exists('depositReviewTimeoutHours', $data)) {
            $settings->deposit_review_timeout_hours = $data['depositReviewTimeoutHours'] !== null
                ? (int) $data['depositReviewTimeoutHours']
                : null;
        }
        if (array_key_exists('inviteLinkExpiryHours', $data)) {
            $settings->invite_link_expiry_hours = max(1, min(168, (int) $data['inviteLinkExpiryHours']));
        }

        $settings->save();

        return $settings->fresh(['outlet']) ?? $settings;
    }

    private function findOrCreateDefaults(int $outletId): OutletReservationSetting
    {
        $existing = OutletReservationSetting::query()->where('outlet_id', $outletId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $outlet = Outlet::query()->find($outletId);
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }

        $baseSlug = Str::slug((string) ($outlet->code ?: $outlet->name));
        $slug = $baseSlug !== '' ? $baseSlug : 'outlet-'.$outletId;
        $candidate = $slug;
        $suffix = 1;
        while (OutletReservationSetting::query()->where('public_slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return OutletReservationSetting::query()->create([
            'outlet_id' => $outletId,
            'public_enabled' => false,
            'public_slug' => $candidate,
            'deposit_mode' => 'percent',
            'deposit_percent' => 50,
            'deposit_flat_amount' => null,
            'preorder_required' => true,
            'invite_link_expiry_hours' => 24,
        ]);
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
