<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
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

class LoyaltyVoucherAnalyticsTest extends TestCase
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

    public function test_analytics_includes_voucher_counters(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-1',
            'full_name' => 'One',
            'name' => 'One',
            'phone' => '081111111111',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $voucherId = (int) LoyaltyVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'V1',
            'name' => 'Voucher',
            'voucher_type' => LoyaltyVoucher::TYPE_MANUAL,
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 10000,
            'is_active' => true,
        ])->id;

        MemberVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'voucher_id' => $voucherId,
            'voucher_code' => 'VIP-TEST01',
            'status' => MemberVoucher::STATUS_ISSUED,
            'issued_at' => now(),
            'notes' => MemberVoucher::campaignNote(99),
        ]);

        MemberVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'member_id' => $member->id,
            'voucher_id' => $voucherId,
            'voucher_code' => 'VIP-TEST02',
            'status' => MemberVoucher::STATUS_CLAIMED,
            'issued_at' => now(),
            'claimed_at' => now(),
        ]);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.vouchersCount', 1)
            ->assertJsonPath('data.issuedVouchers', 1)
            ->assertJsonPath('data.claimedVouchers', 1)
            ->assertJsonPath('data.campaignVoucherIssuanceCount', 1);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_voucher_analytics__'],
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

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Voucher Analytics Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lvan-'.uniqid(),
        ]);
    }
}
