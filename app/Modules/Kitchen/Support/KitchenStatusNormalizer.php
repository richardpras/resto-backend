<?php

namespace App\Modules\Kitchen\Support;

final class KitchenStatusNormalizer
{
    /** Canonical kitchen status values stored on orders for customer-facing flows. */
    public static function forOrder(?string $ticketStatus): string
    {
        $status = strtolower(trim((string) $ticketStatus));

        return match ($status) {
            'in_progress' => 'cooking',
            'preparing', 'in_kitchen', 'cooking' => 'cooking',
            'queued', 'ready', 'served', 'completed', 'cancelled' => $status,
            default => $status,
        };
    }

    public static function isCookingPhase(?string $kitchenStatus): bool
    {
        $normalized = self::forOrder($kitchenStatus);

        return in_array($normalized, ['cooking', 'preparing', 'in_kitchen', 'in_progress'], true);
    }
}
