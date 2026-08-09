<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\CreatesDraftReservations;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ReservationAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use CreatesDraftReservations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_guest_is_denied_for_reservation_lifecycle_endpoints(): void
    {
        [$outlet, $tableId, $reservationId] = $this->seedReservationContext();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/reservations', $this->reservationPayload($outlet->id))->assertUnauthorized();
        $this->getJson('/api/v1/reservations?outletId='.$outlet->id)->assertUnauthorized();
        $this->getJson('/api/v1/reservations/'.$reservationId)->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/cancel')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/mark-no-show')->assertUnauthorized();
    }

    public function test_public_customer_qr_flow_does_not_grant_reservation_access(): void
    {
        [$outlet, $tableId] = $this->seedOutletAndTable();

        $this->postJson('/api/v1/reservations', $this->reservationPayload($outlet->id))->assertUnauthorized();
        $this->getJson('/api/v1/reservations?outletId='.$outlet->id)->assertUnauthorized();
        $this->postJson('/api/v1/reservations/1/confirm')->assertUnauthorized();
        $this->postJson('/api/v1/reservations/1/allocate-table', ['tableId' => $tableId])->assertUnauthorized();
    }

    public function test_finance_only_role_is_denied_without_pos_use(): void
    {
        [$outlet, $tableId, $reservationId] = $this->seedReservationContext();
        $financeUser = $this->createUserWithPermissions(['finance.reconcile', 'finance.shift_close']);
        Passport::actingAs($financeUser);
        $this->assignUserToOutlets($financeUser, [(int) $outlet->id]);

        $this->postJson('/api/v1/reservations', $this->reservationPayload($outlet->id))->assertForbidden();
        $this->getJson('/api/v1/reservations?outletId='.$outlet->id)->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/start-service')->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/cancel')->assertForbidden();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/mark-no-show')->assertForbidden();
    }

    public function test_cashier_with_pos_use_can_manage_reservation_lifecycle(): void
    {
        [$outlet, $tableId] = $this->seedOutletAndTable();
        $cashier = $this->createUserWithPermissions(['pos.use']);
        Passport::actingAs($cashier);
        $this->assignUserToOutlets($cashier, [(int) $outlet->id]);

        [$menuItem] = $this->seedReservationMenuItem((int) $outlet->id);
        $create = $this->postJson('/api/v1/reservations', $this->reservationPayload($outlet->id, (int) $menuItem->id))->assertCreated();
        $reservationId = (int) $create->json('data.id');
        $this->assertSame('pending_deposit', $create->json('data.status'));

        $this->getJson('/api/v1/reservations?outletId='.$outlet->id)->assertOk();
        $this->getJson('/api/v1/reservations/'.$reservationId)->assertOk();

        // Lifecycle after deposit uses draft→confirmed path for authority coverage.
        $lifecycleId = $this->insertDraftReservation((int) $outlet->id, [
            'customer_name' => 'Authority Guest',
            'party_size' => 2,
        ]);
        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/seat')->assertOk();

        PosSession::query()->create([
            'outlet_id' => $outlet->id,
            'opened_by_user_id' => $cashier->id,
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/start-service')->assertOk();
        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/complete')
            ->assertUnprocessable()
            ->assertJsonPath('errors.linkedOrder.0', 'Reservation cannot be completed while linked order remains unsettled.');
        $this->postJson('/api/v1/reservations/'.$lifecycleId.'/mark-no-show')->assertUnprocessable();
    }

    public function test_admin_can_manage_reservation_lifecycle(): void
    {
        [$outlet, $tableId] = $this->seedOutletAndTable();
        $admin = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $reservationId = $this->insertDraftReservation((int) $outlet->id, [
            'customer_name' => 'Authority Guest',
            'party_size' => 2,
        ]);

        $this->postJson('/api/v1/reservations/'.$reservationId.'/confirm')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/allocate-table', ['tableId' => $tableId])->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/check-in')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/seat')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/complete')->assertOk();
        $this->postJson('/api/v1/reservations/'.$reservationId.'/cancel')->assertUnprocessable();
    }

    /** @return array{0: Outlet, 1: int, 2: int} */
    private function seedReservationContext(): array
    {
        [$outlet, $tableId] = $this->seedOutletAndTable();
        $admin = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $reservationId = $this->insertDraftReservation((int) $outlet->id, [
            'customer_name' => 'Authority Guest',
            'party_size' => 2,
        ]);

        return [$outlet, $tableId, $reservationId];
    }

    /** @return array{0: Outlet, 1: int} */
    private function seedOutletAndTable(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Authority Reservation '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'auth-rsv-'.uniqid(),
        ]);
        $tableId = (int) RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-AUTH',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ])->id;

        return [$outlet, $tableId];
    }

    /** @return array<string, mixed> */
    private function reservationPayload(int $outletId, ?int $menuItemId = null): array
    {
        $payload = [
            'outletId' => $outletId,
            'customerName' => 'Authority Guest',
            'partySize' => 2,
            'reservationAt' => now()->addHour()->toISOString(),
        ];
        if ($menuItemId !== null) {
            $payload['items'] = [['menuItemId' => $menuItemId, 'qty' => 1]];
        }

        return $payload;
    }

    /** @param list<string> $permissionCodes */
    private function createUserWithPermissions(array $permissionCodes): User
    {
        $this->seedUserManagementGatePermissions();
        $permissionIds = Permission::query()
            ->whereIn('code', $permissionCodes)
            ->pluck('id')
            ->all();

        $role = Role::query()->create([
            'name' => 'reservation-auth-role-'.uniqid(),
            'description' => 'Reservation authority scoped role',
        ]);
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'email' => 'reservation-auth-'.uniqid().'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
