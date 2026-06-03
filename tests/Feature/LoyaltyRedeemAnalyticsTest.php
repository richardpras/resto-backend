<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
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

class LoyaltyRedeemAnalyticsTest extends TestCase
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

    public function test_analytics_includes_redeem_counters(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-RD',
            'full_name' => 'Redeem Analytics',
            'name' => 'Redeem Analytics',
            'phone' => '081199988877',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        LoyaltyMemberLedger::query()->create([
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_REDEEM,
            'reference_type' => 'redeem',
            'reference_id' => 'redeem-1',
            'points' => -300,
        ]);
        LoyaltyMemberLedger::query()->create([
            'member_id' => $member->id,
            'type' => LoyaltyMemberLedger::TYPE_REDEEM,
            'reference_type' => 'redeem',
            'reference_id' => 'redeem-2',
            'points' => -150,
        ]);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.redeemTransactions', 2)
            ->assertJsonPath('data.redeemedPoints', 450);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_redeem_analytics__'],
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
            'name' => 'Redeem Analytics Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lra-'.uniqid(),
        ]);
    }
}
