<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyVoucherTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_voucher_crud_and_activation(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/loyalty-vouchers', [
            'outletId' => $outlet->id,
            'code' => 'FREE-COFFEE',
            'name' => 'Free Coffee',
            'voucherType' => LoyaltyVoucher::TYPE_MANUAL,
            'valueType' => LoyaltyVoucher::VALUE_FREE_ITEM,
            'value' => 0,
        ])->assertCreated();

        $voucherId = (int) $create->json('data.id');

        $this->patchJson("/api/v1/loyalty-vouchers/{$voucherId}", [
            'name' => 'Free Coffee XL',
        ])->assertOk()->assertJsonPath('data.name', 'Free Coffee XL');

        $this->patchJson("/api/v1/loyalty-vouchers/{$voucherId}/activation", [
            'isActive' => false,
        ])->assertOk()->assertJsonPath('data.isActive', false);

        $this->getJson('/api/v1/loyalty-vouchers?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.code', 'FREE-COFFEE');
    }

    public function test_outlet_isolation_blocks_foreign_voucher(): void
    {
        $admin = $this->actingAsMembersManager();
        $allowed = $this->createOutlet('A');
        $blocked = $this->createOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $foreignId = (int) LoyaltyVoucher::query()->create([
            'outlet_id' => $blocked->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'voucher_type' => LoyaltyVoucher::TYPE_MANUAL,
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 10000,
            'is_active' => true,
        ])->id;

        $this->getJson('/api/v1/loyalty-vouchers/'.$foreignId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_validation_rejects_duplicate_code(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $this->postJson('/api/v1/loyalty-vouchers', [
            'outletId' => $outlet->id,
            'code' => 'DUP',
            'name' => 'First',
            'valueType' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ])->assertCreated();

        $this->postJson('/api/v1/loyalty-vouchers', [
            'outletId' => $outlet->id,
            'code' => 'dup',
            'name' => 'Second',
            'valueType' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 15,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_voucher_admin__'],
            ['description' => 'Members manage'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Voucher Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lv-'.$suffix.uniqid(),
        ]);
    }
}
