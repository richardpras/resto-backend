<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
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

class LoyaltyCampaignSnapshotTest extends TestCase
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

    public function test_snapshot_is_immutable_when_segment_grows(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $memberA = $this->createMember($outlet, 'A');
        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $campaignId = (int) $this->postJson('/api/v1/loyalty-campaigns', [
            'outletId' => $outlet->id,
            'code' => 'SNAP',
            'name' => 'Snapshot test',
            'segmentId' => $segmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")->assertOk();

        $capturedBefore = LoyaltyCampaignAudience::query()->where('campaign_id', $campaignId)->count();
        $this->assertSame(1, $capturedBefore);

        $memberB = $this->createMember($outlet, 'B');

        $this->getJson("/api/v1/loyalty-campaigns/{$campaignId}/audience")
            ->assertOk()
            ->assertJsonPath('data.memberCount', 2);

        $this->getJson("/api/v1/loyalty-campaigns/{$campaignId}/audience-snapshot")
            ->assertOk()
            ->assertJsonPath('data.capturedCount', 1)
            ->assertJsonCount(1, 'data.members');

        $this->assertSame(1, LoyaltyCampaignAudience::query()->where('campaign_id', $campaignId)->count());
        $this->assertDatabaseHas('loyalty_campaign_audiences', [
            'campaign_id' => $campaignId,
            'member_id' => $memberA,
        ]);
        $this->assertDatabaseMissing('loyalty_campaign_audiences', [
            'campaign_id' => $campaignId,
            'member_id' => $memberB,
        ]);
    }

    public function test_duplicate_snapshot_entries_not_created_on_reactivate_guard(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $this->createMember($outlet);
        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR2',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $campaignId = (int) $this->postJson('/api/v1/loyalty-campaigns', [
            'outletId' => $outlet->id,
            'code' => 'DUP',
            'name' => 'Dup snapshot',
            'segmentId' => $segmentId,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")->assertOk();
        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")->assertOk();

        $this->assertSame(1, LoyaltyCampaignAudience::query()->where('campaign_id', $campaignId)->count());
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_campaign_snap__'],
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
            'name' => 'Snap Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcsn-'.uniqid(),
        ]);
    }

    private function createMember(Outlet $outlet, string $suffix = ''): int
    {
        return (int) Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-'.$suffix.uniqid(),
            'full_name' => 'Member '.$suffix,
            'name' => 'Member '.$suffix,
            'phone' => '0813'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ])->id;
    }
}
