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

class LoyaltyCampaignExecutionTest extends TestCase
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

    public function test_activate_captures_snapshot_and_sets_activated_at(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $segmentId = $this->createSegment($outlet);

        $campaignId = $this->createCampaignApi($outlet, $segmentId, 'EXEC1');

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', LoyaltyCampaign::STATUS_ACTIVE)
            ->assertJsonPath('data.capturedCount', 1);

        $this->assertDatabaseHas('loyalty_campaigns', [
            'id' => $campaignId,
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
        ]);
        $this->assertNotNull(LoyaltyCampaign::query()->find($campaignId)?->activated_at);

        $this->assertSame(1, LoyaltyCampaignAudience::query()->where('campaign_id', $campaignId)->count());
    }

    public function test_complete_and_cancel_transitions(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $segmentId = $this->createSegment($outlet);
        $campaignId = $this->createCampaignApi($outlet, $segmentId, 'EXEC2');

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")->assertOk();

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', LoyaltyCampaign::STATUS_COMPLETED);

        $this->assertNotNull(LoyaltyCampaign::query()->find($campaignId)?->completed_at);

        $campaignId2 = $this->createCampaignApi($outlet, $segmentId, 'EXEC3');
        $this->patchJson("/api/v1/loyalty-campaigns/{$campaignId2}/status", [
            'status' => LoyaltyCampaign::STATUS_SCHEDULED,
        ])->assertOk();

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId2}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', LoyaltyCampaign::STATUS_CANCELLED);

        $this->assertNotNull(LoyaltyCampaign::query()->find($campaignId2)?->cancelled_at);
    }

    public function test_invalid_execution_transitions_rejected(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $segmentId = $this->createSegment($outlet);
        $campaignId = $this->createCampaignApi($outlet, $segmentId, 'EXEC4');

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")->assertOk();
        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/activate")->assertOk();

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/complete")->assertOk();
        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_outlet_isolation_blocks_foreign_campaign_execution(): void
    {
        $admin = $this->actingAsMembersManager();
        $outletA = $this->createOutlet('A');
        $outletB = $this->createOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);
        $segmentB = $this->createSegment($outletB);
        $foreignId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outletB->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'segment_id' => $segmentB,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'status' => LoyaltyCampaign::STATUS_DRAFT,
        ])->id;

        $this->postJson("/api/v1/loyalty-campaigns/{$foreignId}/activate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_campaign_exec__'],
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
            'name' => 'Exec Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcex-'.$suffix.uniqid(),
        ]);
    }

    private function createMember(Outlet $outlet): int
    {
        return (int) Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-'.uniqid(),
            'full_name' => 'Member',
            'name' => 'Member',
            'phone' => '0812'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ])->id;
    }

    private function createSegment(Outlet $outlet): int
    {
        $this->createMember($outlet);

        return (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR-'.uniqid(),
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;
    }

    private function createCampaignApi(Outlet $outlet, int $segmentId, string $code): int
    {
        return (int) $this->postJson('/api/v1/loyalty-campaigns', [
            'outletId' => $outlet->id,
            'code' => $code,
            'name' => 'Campaign '.$code,
            'segmentId' => $segmentId,
        ])->assertCreated()->json('data.id');
    }
}
