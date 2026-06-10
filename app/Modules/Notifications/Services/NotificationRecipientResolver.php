<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Collection;

final class NotificationRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function usersForOutlet(int $outletId, ?string $permissionCode = null): Collection
    {
        if ($outletId < 1) {
            return collect();
        }

        $query = User::query()
            ->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outletId));

        $users = $query->get();

        if ($permissionCode === null || trim($permissionCode) === '') {
            return $users;
        }

        return $users->filter(static fn (User $user): bool => $user->hasPermission($permissionCode))->values();
    }
}
