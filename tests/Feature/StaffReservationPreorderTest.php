<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesDraftReservations;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class StaffReservationPreorderTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use CreatesDraftReservations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('local');
    }

    public function test_staff_create_requires_items_and_sets_pending_deposit(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($user, [$outlet->id]);
        [$menuItem, $price] = $this->seedReservationMenuItem((int) $outlet->id, 80000);

        $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Staff Guest',
            'partySize' => 3,
            'reservationAt' => now()->addDay()->toISOString(),
        ])->assertUnprocessable();

        $created = $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'Staff Guest',
            'partySize' => 3,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [
                ['menuItemId' => $menuItem->id, 'qty' => 2],
            ],
        ])->assertCreated();

        $created->assertJsonPath('data.status', 'pending_deposit')
            ->assertJsonPath('data.requiredDepositAmount', (int) ($price * 2 * 0.5))
            ->assertJsonPath('data.linkedOrder.items.0.name', $menuItem->name);

        $this->getJson('/api/v1/reservations/menu?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $menuItem->id);
    }

    public function test_staff_upload_proof_then_approve(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($user, [$outlet->id]);
        [$menuItem] = $this->seedReservationMenuItem((int) $outlet->id, 100000);

        $id = (int) $this->postJson('/api/v1/reservations', [
            'outletId' => $outlet->id,
            'customerName' => 'DP Guest',
            'partySize' => 2,
            'reservationAt' => now()->addDay()->toISOString(),
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertCreated()->json('data.id');

        $this->post('/api/v1/reservations/'.$id.'/deposit-proof', [
            'proof' => UploadedFile::fake()->image('staff-proof.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.status', 'deposit_submitted');

        $this->postJson('/api/v1/reservations/'.$id.'/approve-deposit')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_deposit_percent_settings_reject_below_fifty(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($user, [$outlet->id]);
        $this->seedReservationMenuItem((int) $outlet->id);

        $this->patchJson('/api/v1/outlets/'.$outlet->id.'/reservation-settings', [
            'depositMode' => 'percent',
            'depositPercent' => 40,
        ])->assertUnprocessable();
    }

    private function createOutlet(): \App\Models\Modules\Settings\Domain\Outlet
    {
        return \App\Models\Modules\Settings\Domain\Outlet::query()->create([
            'name' => 'Staff RSV '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'staff-rsv-'.uniqid(),
        ]);
    }
}
