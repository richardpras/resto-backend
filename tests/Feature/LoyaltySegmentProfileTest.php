<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltySegmentProfileTest extends TestCase
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

    public function test_profile_lists_matching_member_segments(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-PROF',
            'full_name' => 'June Member',
            'name' => 'June Member',
            'phone' => '081333333333',
            'birth_date' => '1995-06-20',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $birthdaySegment = MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'BDAY',
            'name' => 'Birthday this month',
            'segment_type' => MemberSegment::TYPE_BIRTHDAY_MONTH,
            'config_json' => [],
            'is_active' => true,
        ]);

        MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'VIP',
            'name' => 'VIP spenders',
            'segment_type' => MemberSegment::TYPE_VIP_SPENDER,
            'config_json' => ['minimum_spending' => 5000000],
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.memberSegments.0.id', (string) $birthdaySegment->id)
            ->assertJsonPath('data.memberSegments.0.code', 'BDAY')
            ->assertJsonPath('data.memberSegments.0.name', 'Birthday this month');

        Carbon::setTestNow();
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_segment_profile__'],
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
            'name' => 'Segment Profile Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lsegp-'.uniqid(),
        ]);
    }
}
