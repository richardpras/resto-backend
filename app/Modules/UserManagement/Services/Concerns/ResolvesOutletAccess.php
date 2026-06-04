<?php

namespace App\Modules\UserManagement\Services\Concerns;

use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

trait ResolvesOutletAccess
{
    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $resolver = app(OutletAccessResolver::class);
        $allowed = $resolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }

    private function userCanAccessOutlet(User $user, int $outletId): bool
    {
        $resolver = app(OutletAccessResolver::class);

        return in_array($outletId, $resolver->allowedOutletIds($user), true);
    }
}
