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

class LoyaltyMemberVoucherTest extends TestCase
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

    public function test_issue_and_status_transitions(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createMember($outlet);
        $voucher = LoyaltyVoucher::query()->findOrFail($this->createVoucher($outlet));

        $memberVoucher = app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->issue(
            $admin,
            $member,
            $voucher,
        );

        $memberVoucherId = (int) $memberVoucher->id;

        $this->getJson("/api/v1/members/{$member->id}/vouchers?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.0.status', MemberVoucher::STATUS_ISSUED);

        $this->patchJson("/api/v1/member-vouchers/{$memberVoucherId}/status", [
            'status' => MemberVoucher::STATUS_CLAIMED,
        ])->assertOk()->assertJsonPath('data.status', MemberVoucher::STATUS_CLAIMED);

        $this->patchJson("/api/v1/member-vouchers/{$memberVoucherId}/status", [
            'status' => MemberVoucher::STATUS_REDEEMED,
        ])->assertOk()->assertJsonPath('data.status', MemberVoucher::STATUS_REDEEMED);

        $this->patchJson("/api/v1/member-vouchers/{$memberVoucherId}/status", [
            'status' => MemberVoucher::STATUS_CANCELLED,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_profile_shows_available_vouchers_and_history(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createMember($outlet);
        $voucher = LoyaltyVoucher::query()->findOrFail($this->createVoucher($outlet));

        app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->issue($admin, $member, $voucher);

        $this->getJson("/api/v1/members/{$member->id}/profile?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.availableVouchers.0.name', 'Free Drink')
            ->assertJsonPath('data.availableVouchers.0.status', MemberVoucher::STATUS_ISSUED)
            ->assertJsonCount(1, 'data.voucherHistory');
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createMember($outlet);
        $voucher = LoyaltyVoucher::query()->findOrFail($this->createVoucher($outlet));

        $memberVoucher = app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->issue(
            $admin,
            $member,
            $voucher,
        );

        $this->patchJson("/api/v1/member-vouchers/{$memberVoucher->id}/status", [
            'status' => MemberVoucher::STATUS_REDEEMED,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_member_voucher_admin__'],
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
            'name' => 'Member Voucher Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lmv-'.uniqid(),
        ]);
    }

    private function createMember(Outlet $outlet): Member
    {
        return Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-'.uniqid(),
            'full_name' => 'Member',
            'name' => 'Member',
            'phone' => '0812'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function createVoucher(Outlet $outlet): int
    {
        return (int) LoyaltyVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'VIP',
            'name' => 'Free Drink',
            'voucher_type' => LoyaltyVoucher::TYPE_MANUAL,
            'value_type' => LoyaltyVoucher::VALUE_FREE_ITEM,
            'value' => 0,
            'is_active' => true,
        ])->id;
    }
}
