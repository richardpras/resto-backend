<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
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

class LoyaltyCampaignAnalyticsTest extends TestCase
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

    public function test_analytics_includes_campaign_summary(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-1',
            'full_name' => 'One',
            'name' => 'One',
            'phone' => '081111111111',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'C1',
            'name' => 'Campaign one',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.campaignsCount', 1)
            ->assertJsonPath('data.activeCampaigns', 1)
            ->assertJsonPath('data.campaignSummary.0.campaign.code', 'C1')
            ->assertJsonPath('data.campaignSummary.0.audienceCount', 1)
            ->assertJsonPath('data.campaignExecutionSummary.0.capturedCount', 0);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_campaign_analytics__'],
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
            'name' => 'Campaign Analytics Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcmpan-'.uniqid(),
        ]);
    }
}
