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

class LoyaltyCampaignTest extends TestCase
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

    public function test_campaign_crud_and_status_transitions(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $segmentId = $this->createSegment($outlet);

        $create = $this->postJson('/api/v1/loyalty-campaigns', [
            'outletId' => $outlet->id,
            'code' => 'SUMMER',
            'name' => 'Summer outreach',
            'description' => 'VIP campaign',
            'segmentId' => $segmentId,
            'scheduledAt' => '2026-07-01T10:00:00Z',
        ])->assertCreated();

        $campaignId = (int) $create->json('data.id');
        $this->assertDatabaseHas('loyalty_campaigns', [
            'id' => $campaignId,
            'status' => LoyaltyCampaign::STATUS_DRAFT,
            'segment_id' => $segmentId,
        ]);

        $this->patchJson("/api/v1/loyalty-campaigns/{$campaignId}", [
            'name' => 'Summer outreach v2',
        ])->assertOk()->assertJsonPath('data.name', 'Summer outreach v2');

        $this->patchJson("/api/v1/loyalty-campaigns/{$campaignId}/status", [
            'status' => LoyaltyCampaign::STATUS_SCHEDULED,
        ])->assertOk()->assertJsonPath('data.status', LoyaltyCampaign::STATUS_SCHEDULED);

        $this->patchJson("/api/v1/loyalty-campaigns/{$campaignId}/status", [
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', LoyaltyCampaign::STATUS_ACTIVE)
            ->assertJsonPath('data.capturedCount', 1);

        $this->assertNotNull(LoyaltyCampaign::query()->find($campaignId)?->activated_at);
        $this->assertDatabaseHas('loyalty_campaign_audiences', [
            'campaign_id' => $campaignId,
        ]);

        $this->patchJson("/api/v1/loyalty-campaigns/{$campaignId}/status", [
            'status' => LoyaltyCampaign::STATUS_COMPLETED,
        ])->assertOk()->assertJsonPath('data.status', LoyaltyCampaign::STATUS_COMPLETED);

        $this->getJson('/api/v1/loyalty-campaigns?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $campaignId);
    }

    public function test_outlet_isolation_blocks_foreign_campaign(): void
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

        $this->getJson('/api/v1/loyalty-campaigns/'.$foreignId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_cannot_link_segment_from_another_outlet(): void
    {
        $admin = $this->actingAsMembersManager();
        $outletA = $this->createOutlet('A');
        $outletB = $this->createOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);
        $segmentB = $this->createSegment($outletB);

        $this->postJson('/api/v1/loyalty-campaigns', [
            'outletId' => $outletA->id,
            'code' => 'BAD',
            'name' => 'Bad link',
            'segmentId' => $segmentB,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['segmentId']);
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $segmentId = $this->createSegment($outlet);

        $campaignId = (int) $this->postJson('/api/v1/loyalty-campaigns', [
            'outletId' => $outlet->id,
            'code' => 'DRAFT',
            'name' => 'Draft only',
            'segmentId' => $segmentId,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/loyalty-campaigns/{$campaignId}/status", [
            'status' => LoyaltyCampaign::STATUS_COMPLETED,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_campaign_admin__'],
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
            'name' => 'Campaign Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcmp-'.$suffix.uniqid(),
        ]);
    }

    private function createSegment(Outlet $outlet): int
    {
        Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-'.uniqid(),
            'full_name' => 'Member',
            'name' => 'Member',
            'phone' => '0812'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        return (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR-'.uniqid(),
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;
    }
}
