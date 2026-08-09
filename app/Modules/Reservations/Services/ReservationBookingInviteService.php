<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\ReservationBookingInvite;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use App\Models\User;
use App\Modules\Settings\Services\CustomerAppUrlResolver;
use App\Modules\Settings\Services\OutletReservationSettingsService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationBookingInviteService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly OutletReservationSettingsService $settingsService,
        private readonly CustomerAppUrlResolver $customerAppUrlResolver,
    ) {}

    /**
     * @return array{token: string, expiresAt: string, urlPath: string, absoluteUrl: string|null}
     */
    public function create(User $user, int $outletId): array
    {
        $this->assertOutletAllowed($user, $outletId);

        $outlet = Outlet::query()->find($outletId);
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }

        $settings = $this->settingsService->show($user, $outletId);
        $hours = max(1, min(168, (int) ($settings->invite_link_expiry_hours ?? 24)));

        $invite = ReservationBookingInvite::query()->create([
            'outlet_id' => $outletId,
            'token' => $this->generateToken(),
            'expires_at' => now()->addHours($hours),
            'max_uses' => 1,
            'used_count' => 0,
            'created_by_user_id' => $user->id,
            'revoked_at' => null,
        ]);

        $urlPath = '/reserve/invite/'.$invite->token;
        $base = $this->customerAppUrlResolver->resolve();

        return [
            'token' => (string) $invite->token,
            'expiresAt' => $invite->expires_at?->toISOString() ?? now()->toISOString(),
            'urlPath' => $urlPath,
            'absoluteUrl' => $base !== null ? $base.$urlPath : null,
        ];
    }

    public function resolveValid(string $token): ReservationBookingInvite
    {
        $invite = ReservationBookingInvite::query()
            ->where('token', $token)
            ->with(['outlet'])
            ->first();

        if ($invite === null || ! $invite->isValid()) {
            throw (new ModelNotFoundException)->setModel(ReservationBookingInvite::class, [$token]);
        }

        return $invite;
    }

    public function resolveSettingsForInvite(string $token): OutletReservationSetting
    {
        $invite = $this->resolveValid($token);
        $settings = OutletReservationSetting::query()
            ->where('outlet_id', $invite->outlet_id)
            ->with('outlet')
            ->first();

        if ($settings === null) {
            throw (new ModelNotFoundException)->setModel(OutletReservationSetting::class, [(string) $invite->outlet_id]);
        }

        return $settings;
    }

    public function consume(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $invite = ReservationBookingInvite::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if ($invite === null || ! $invite->isValid()) {
                throw (new ModelNotFoundException)->setModel(ReservationBookingInvite::class, [$token]);
            }

            $invite->used_count = (int) $invite->used_count + 1;
            $invite->save();
        });
    }

    private function generateToken(): string
    {
        do {
            $token = Str::lower(Str::random(40));
        } while (ReservationBookingInvite::query()->where('token', $token)->exists());

        return $token;
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
