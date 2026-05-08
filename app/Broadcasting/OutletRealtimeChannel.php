<?php

namespace App\Broadcasting;

use App\Models\User;

class OutletRealtimeChannel
{
    public function join(User $user, int $outletId): bool
    {
        return $user->outlets()
            ->where('outlets.id', $outletId)
            ->where('outlets.status', 'active')
            ->exists();
    }
}
