<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Reservations\Domain\ReservationBookingInvite;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationBookingInviteTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_staff_can_generate_invite_and_guest_can_book_once(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        [$outlet, $menuItem] = $this->seedInviteOutlet(publicEnabled: false);

        $this->assignUserToOutlets($user, [$outlet->id]);

        $create = $this->postJson('/api/v1/outlets/'.$outlet->id.'/reservation-invites')
            ->assertCreated();

        $token = (string) $create->json('data.token');
        $this->assertNotSame('', $token);
        $this->assertSame('/reserve/invite/'.$token, (string) $create->json('data.urlPath'));

        $this->getJson('/api/v1/public/reserve/invite/'.$token)
            ->assertOk()
            ->assertJsonPath('outlet.id', $outlet->id)
            ->assertJsonPath('publicSlug', 'invite-outlet')
            ->assertJsonPath('invite.token', $token);

        $this->getJson('/api/v1/public/reserve/invite/'.$token.'/menu')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $menuItem->id);

        $this->postJson('/api/v1/public/reserve/invite/'.$token, [
            'customerName' => 'Invite Guest',
            'customerPhone' => '08123456789',
            'partySize' => 2,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_deposit')
            ->assertJsonPath('data.source', 'public');

        $this->assertDatabaseHas('reservation_booking_invites', [
            'token' => $token,
            'used_count' => 1,
        ]);

        $this->postJson('/api/v1/public/reserve/invite/'.$token, [
            'customerName' => 'Second Guest',
            'partySize' => 2,
            'reservationAt' => now()->addDays(2)->toISOString(),
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertNotFound();
    }

    public function test_expired_invite_returns_404(): void
    {
        [, $menuItem] = $this->seedInviteOutlet(publicEnabled: false);

        $invite = ReservationBookingInvite::query()->create([
            'outlet_id' => Outlet::query()->value('id'),
            'token' => 'expired-token-abcdefghijklmnopqrstuvwxyz12',
            'expires_at' => now()->subHour(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);

        $this->getJson('/api/v1/public/reserve/invite/'.$invite->token)->assertNotFound();

        $this->postJson('/api/v1/public/reserve/invite/'.$invite->token, [
            'customerName' => 'Late Guest',
            'partySize' => 2,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertNotFound();
    }

    public function test_revoked_invite_returns_404(): void
    {
        [, $menuItem] = $this->seedInviteOutlet(publicEnabled: true);

        $invite = ReservationBookingInvite::query()->create([
            'outlet_id' => Outlet::query()->value('id'),
            'token' => 'revoked-token-abcdefghijklmnopqrstuvwxyz12',
            'expires_at' => now()->addDay(),
            'max_uses' => 1,
            'used_count' => 0,
            'revoked_at' => now(),
        ]);

        $this->getJson('/api/v1/public/reserve/invite/'.$invite->token)->assertNotFound();

        $this->postJson('/api/v1/public/reserve/invite/'.$invite->token, [
            'customerName' => 'Revoked Guest',
            'partySize' => 2,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertNotFound();
    }

    public function test_invite_uses_configured_expiry_hours(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        [$outlet] = $this->seedInviteOutlet(publicEnabled: false, inviteExpiryHours: 48);
        $this->assignUserToOutlets($user, [$outlet->id]);

        $this->postJson('/api/v1/outlets/'.$outlet->id.'/reservation-invites')
            ->assertCreated();

        $invite = ReservationBookingInvite::query()->where('outlet_id', $outlet->id)->latest('id')->firstOrFail();
        $hours = $invite->created_at->diffInHours($invite->expires_at);
        $this->assertGreaterThanOrEqual(47, $hours);
        $this->assertLessThanOrEqual(49, $hours);
    }

    /** @return array{0: Outlet, 1: MenuItem} */
    private function seedInviteOutlet(bool $publicEnabled, int $inviteExpiryHours = 24): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Invite Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'invite-outlet',
        ]);

        OutletReservationSetting::query()->create([
            'outlet_id' => $outlet->id,
            'public_enabled' => $publicEnabled,
            'public_slug' => 'invite-outlet',
            'deposit_mode' => 'percent',
            'deposit_percent' => 50,
            'deposit_flat_amount' => null,
            'preorder_required' => true,
            'deposit_instructions' => 'Transfer to BCA 123',
            'invite_link_expiry_hours' => $inviteExpiryHours,
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'name' => 'Nasi Goreng',
            'category' => 'Main',
            'price' => 100000,
            'available' => true,
        ]);

        MenuItemOutlet::query()->create([
            'menu_item_id' => $menuItem->id,
            'outlet_id' => $outlet->id,
            'is_active' => true,
        ]);

        return [$outlet, $menuItem];
    }
}
