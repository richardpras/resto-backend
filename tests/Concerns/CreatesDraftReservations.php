<?php

namespace Tests\Concerns;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;

trait CreatesDraftReservations
{
    /**
     * Lifecycle tests still use draft status (confirm/check-in path).
     * Staff API create now requires pre-order + pending_deposit, so insert directly.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function insertDraftReservation(int $outletId, array $overrides = []): int
    {
        $reservation = Reservation::query()->create(array_merge([
            'outlet_id' => $outletId,
            'table_id' => null,
            'reservation_code' => 'RSV-TEST-'.strtoupper(substr(uniqid(), -6)),
            'customer_name' => 'John Doe',
            'customer_phone' => '08123456789',
            'party_size' => 4,
            'reservation_at' => now()->addHour(),
            'status' => 'draft',
            'source' => 'staff',
        ], $overrides));

        return (int) $reservation->id;
    }

    /** @return array{0: MenuItem, 1: float} menu item and unit price */
    protected function seedReservationMenuItem(int $outletId, float $price = 100000): array
    {
        OutletReservationSetting::query()->updateOrCreate(
            ['outlet_id' => $outletId],
            [
                'public_enabled' => false,
                'public_slug' => 'outlet-'.$outletId.'-'.uniqid(),
                'deposit_mode' => 'percent',
                'deposit_percent' => 50,
                'deposit_flat_amount' => null,
                'preorder_required' => true,
            ],
        );

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'name' => 'Reservation Item '.uniqid(),
            'category' => 'Main',
            'price' => $price,
            'available' => true,
        ]);

        MenuItemOutlet::query()->create([
            'menu_item_id' => $menuItem->id,
            'outlet_id' => $outletId,
            'is_active' => true,
        ]);

        return [$menuItem, $price];
    }
}
