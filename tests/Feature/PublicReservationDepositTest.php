<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Reservations\Domain\ReservationTableAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PublicReservationDepositTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('local');
    }

    public function test_public_create_with_percent_deposit_and_preorder(): void
    {
        [$outlet, $menuItem] = $this->seedPublicOutlet('percent', 50, null, true);

        $response = $this->postJson('/api/v1/public/reserve/demo-outlet', [
            'customerName' => 'Guest A',
            'customerPhone' => '08111111111',
            'partySize' => 4,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 2]],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending_deposit')
            ->assertJsonPath('data.requiredDepositAmount', 250000)
            ->assertJsonPath('data.source', 'public');

        $this->assertDatabaseHas('reservations', [
            'outlet_id' => $outlet->id,
            'status' => 'pending_deposit',
            'source' => 'public',
            'required_deposit_amount' => 250000,
        ]);
    }

    public function test_flat_mode_without_preorder_when_not_required(): void
    {
        $this->seedPublicOutlet('flat', null, 150000, false);

        $this->postJson('/api/v1/public/reserve/demo-outlet', [
            'customerName' => 'Guest B',
            'partySize' => 2,
            'reservationAt' => now()->addDays(2)->toISOString(),
            'items' => [],
        ])->assertCreated()
            ->assertJsonPath('data.requiredDepositAmount', 150000);
    }

    public function test_preorder_required_without_items_returns_422(): void
    {
        $this->seedPublicOutlet('flat', null, 100000, true);

        $this->postJson('/api/v1/public/reserve/demo-outlet', [
            'customerName' => 'Guest C',
            'partySize' => 2,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('errors.items.0', 'Pre-order menu items are required for this outlet.');
    }

    public function test_upload_proof_moves_to_deposit_submitted(): void
    {
        $this->seedPublicOutlet('flat', null, 100000, false);
        $code = $this->createPublicReservation();

        $this->post('/api/v1/public/reservations/'.$code.'/deposit-proof', [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.status', 'deposit_submitted');
    }

    public function test_staff_approve_deposit_confirms_reservation(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->seedPublicOutlet('flat', null, 100000, false);
        $code = $this->createPublicReservation();
        $this->post('/api/v1/public/reservations/'.$code.'/deposit-proof', [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();

        $reservationId = (int) Reservation::query()->where('reservation_code', $code)->value('id');
        $outletId = (int) Reservation::query()->whereKey($reservationId)->value('outlet_id');
        $this->assignUserToOutlets($user, [$outletId]);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/approve-deposit')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.approvedDepositAmount', 100000);
    }

    public function test_staff_reject_deposit_cancels_reservation(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->seedPublicOutlet('flat', null, 100000, false);
        $code = $this->createPublicReservation();
        $reservationId = (int) Reservation::query()->where('reservation_code', $code)->value('id');
        $outletId = (int) Reservation::query()->whereKey($reservationId)->value('outlet_id');
        $this->assignUserToOutlets($user, [$outletId]);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/reject-deposit', [
            'reason' => 'Invalid proof',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_guest_cannot_access_staff_deposit_endpoints(): void
    {
        $this->getJson('/api/v1/reservations/pending-deposits?outletId=1')->assertUnauthorized();
    }

    public function test_cannot_allocate_table_while_pending_deposit(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        [$outlet] = $this->seedPublicOutlet('flat', null, 100000, false);
        $code = $this->createPublicReservation();
        $reservationId = (int) Reservation::query()->where('reservation_code', $code)->value('id');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-1',
            'status' => 'active',
            'active' => true,
        ])->id;

        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', [
            'tableId' => $tableId,
        ])->assertUnprocessable();
    }

    public function test_start_service_reuses_linked_preorder(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        [$outlet, $menuItem] = $this->seedPublicOutlet('percent', 50, null, true);
        $code = $this->createPublicReservation();
        $reservation = Reservation::query()->where('reservation_code', $code)->firstOrFail();
        $linkedOrderId = (int) $reservation->linked_order_id;
        $this->assignUserToOutlets($user, [$outlet->id]);

        $this->post('/api/v1/public/reservations/'.$code.'/deposit-proof', [
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservation->id.'/approve-deposit')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservation->id.'/check-in')->assertOk();

        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-2',
            'status' => 'active',
            'active' => true,
        ])->id;
        $this->postJson('/api/v1/reservations/'.$reservation->id.'/allocate-table', ['tableId' => $tableId])->assertOk();

        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 0,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/reservations/'.$reservation->id.'/start-service')
            ->assertOk()
            ->assertJsonPath('data.linkedOrderId', $linkedOrderId);

        $this->assertDatabaseHas('orders', [
            'id' => $linkedOrderId,
            'table_id' => $tableId,
        ]);
        $this->assertSame(1, Reservation::query()->where('linked_order_id', $linkedOrderId)->count());
    }

    /** @return array{0: Outlet, 1: MenuItem} */
    private function seedPublicOutlet(
        string $depositMode,
        ?float $percent,
        ?float $flat,
        bool $preorderRequired,
    ): array {
        $outlet = Outlet::query()->create([
            'name' => 'Demo Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'demo-outlet',
        ]);

        OutletReservationSetting::query()->create([
            'outlet_id' => $outlet->id,
            'public_enabled' => true,
            'public_slug' => 'demo-outlet',
            'deposit_mode' => $depositMode,
            'deposit_percent' => $percent,
            'deposit_flat_amount' => $flat,
            'preorder_required' => $preorderRequired,
            'deposit_instructions' => 'Transfer to BCA 123',
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'name' => 'Nasi Goreng',
            'category' => 'Main',
            'price' => 250000,
            'available' => true,
        ]);

        MenuItemOutlet::query()->create([
            'menu_item_id' => $menuItem->id,
            'outlet_id' => $outlet->id,
            'is_active' => true,
        ]);

        return [$outlet, $menuItem];
    }

    private function createPublicReservation(): string
    {
        $response = $this->postJson('/api/v1/public/reserve/demo-outlet', [
            'customerName' => 'Guest',
            'partySize' => 2,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [['menuItemId' => MenuItem::query()->value('id'), 'qty' => 2]],
        ])->assertCreated();

        return (string) $response->json('data.reservationCode');
    }
}
